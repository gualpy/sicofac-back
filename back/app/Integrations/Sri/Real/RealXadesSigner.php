<?php

namespace App\Integrations\Sri\Real;

use App\Integrations\Sri\Contracts\XadesSignerInterface;
use App\Models\CompanyCertificate;
use App\Services\Certificates\Pkcs12Reader;
use DOMDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Signs a "factura" XML per the SRI's XAdES-BES policy (Ficha Tecnica de
 * Comprobantes Electronicos, sections 6.1-6.8 and Anexo 14), which follows
 * Spain's Facturae/MITyC XAdES profile: ENVELOPED signature, plain C14N
 * (not exclusive), and the algorithms the SRI mandates -- SHA-1 digests and
 * RSA-SHA1 signing (section 6.8). These are fixed by the SRI's own spec, not
 * a security choice made here; a modern algorithm would simply be rejected
 * as non-conformant by the SRI's validator.
 *
 * Built by hand against the SRI's own worked example (Anexo 14) rather than
 * a generic XML-DSig library, because the SRI's structure has quirks a
 * generic signer wouldn't reproduce: a second <ds:Reference> that digests
 * the whole <ds:KeyInfo> element (not just the raw certificate) to prevent
 * certificate substitution (section 6.5), and the specific XAdES
 * SignedProperties layout shown in the example.
 */
class RealXadesSigner implements XadesSignerInterface
{
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const NS_ETSI = 'http://uri.etsi.org/01903/v1.3.2#';

    public function sign(string $xml, CompanyCertificate $certificate): string
    {
        if (! str_contains($xml, 'id="comprobante"')) {
            throw new RuntimeException('XML root must carry id="comprobante" to be signed enveloped.');
        }

        [$certPem, $privateKeyPem] = $this->loadKeyPair($certificate);

        $digestComprobante = $this->digest($this->canonicalize($xml));

        $certDer = base64_encode($this->pemToDer($certPem));
        $digestCert = $this->digest(base64_decode($certDer));

        $parsed = openssl_x509_parse($certPem);
        if (! is_array($parsed)) {
            throw new RuntimeException('Unable to parse the signing certificate.');
        }
        $issuerDn = collect($parsed['issuer'])
            ->map(fn ($value, $key) => strtoupper((string) $key).'='.$value)
            ->implode(',');
        $serialNumber = (string) $parsed['serialNumber'];

        $publicKeyDetails = openssl_pkey_get_details(openssl_pkey_get_public($certPem));
        if (! is_array($publicKeyDetails) || ! isset($publicKeyDetails['rsa'])) {
            throw new RuntimeException('Signing certificate does not carry an RSA public key.');
        }
        $modulus = base64_encode($publicKeyDetails['rsa']['n']);
        $exponent = base64_encode($publicKeyDetails['rsa']['e']);

        $signingTime = Carbon::now('America/Guayaquil')->format('Y-m-d\TH:i:sP');

        $suffix = fn () => (string) random_int(100000000, 999999999);
        $sigId = 'Signature'.$suffix();
        $signedInfoId = $sigId.'-SignedInfo'.$suffix();
        $keyInfoId = 'Certificate'.$suffix();
        $signatureValueId = 'SignatureValue'.$suffix();
        $signedPropertiesId = $sigId.'-SignedProperties'.$suffix();
        $objectId = $sigId.'-Object'.$suffix();
        $comprobanteRefId = 'Reference-ID-'.$suffix();
        $signedPropertiesRefId = 'SignedPropertiesID'.$suffix();

        // Namespaces declared here must match the full set that ends up in
        // scope once this element is embedded under <ds:Signature xmlns:ds
        // xmlns:etsi>: non-exclusive C14N (mandated by the SRI) serializes
        // every in-scope namespace, inherited ones included, so digesting
        // this fragment standalone with a narrower scope would silently
        // produce different bytes than a validator re-canonicalizing the
        // embedded node.
        $keyInfoXml = '<ds:KeyInfo xmlns:ds="'.self::NS_DS.'" xmlns:etsi="'.self::NS_ETSI.'" Id="'.$keyInfoId.'">'
            .'<ds:X509Data><ds:X509Certificate>'.$certDer.'</ds:X509Certificate></ds:X509Data>'
            .'<ds:KeyValue><ds:RSAKeyValue><ds:Modulus>'.$modulus.'</ds:Modulus><ds:Exponent>'.$exponent.'</ds:Exponent></ds:RSAKeyValue></ds:KeyValue>'
            .'</ds:KeyInfo>';
        $digestKeyInfo = $this->digest($this->canonicalize($keyInfoXml));

        $signedPropertiesXml = '<etsi:SignedProperties xmlns:etsi="'.self::NS_ETSI.'" xmlns:ds="'.self::NS_DS.'" Id="'.$signedPropertiesId.'">'
            .'<etsi:SignedSignatureProperties>'
            .'<etsi:SigningTime>'.$signingTime.'</etsi:SigningTime>'
            .'<etsi:SigningCertificate><etsi:Cert><etsi:CertDigest>'
            .'<ds:DigestMethod Algorithm="'.self::NS_DS.'sha1"/>'
            .'<ds:DigestValue>'.$digestCert.'</ds:DigestValue>'
            .'</etsi:CertDigest><etsi:IssuerSerial>'
            .'<ds:X509IssuerName>'.htmlspecialchars($issuerDn, ENT_XML1).'</ds:X509IssuerName>'
            .'<ds:X509SerialNumber>'.$serialNumber.'</ds:X509SerialNumber>'
            .'</etsi:IssuerSerial></etsi:Cert></etsi:SigningCertificate>'
            .'</etsi:SignedSignatureProperties>'
            .'<etsi:SignedDataObjectProperties>'
            .'<etsi:DataObjectFormat ObjectReference="#'.$comprobanteRefId.'">'
            .'<etsi:Description>contenido comprobante</etsi:Description>'
            .'<etsi:MimeType>text/xml</etsi:MimeType>'
            .'</etsi:DataObjectFormat>'
            .'</etsi:SignedDataObjectProperties>'
            .'</etsi:SignedProperties>';
        $digestSignedProperties = $this->digest($this->canonicalize($signedPropertiesXml));

        // Same in-scope-namespace reasoning as $keyInfoXml above: this
        // fragment is signed standalone but validated as an embedded node,
        // so its declared namespaces must match the embedded scope.
        $signedInfoXml = '<ds:SignedInfo xmlns:ds="'.self::NS_DS.'" xmlns:etsi="'.self::NS_ETSI.'" Id="'.$signedInfoId.'">'
            .'<ds:CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            .'<ds:SignatureMethod Algorithm="'.self::NS_DS.'rsa-sha1"/>'
            .'<ds:Reference Id="'.$signedPropertiesRefId.'" Type="http://uri.etsi.org/01903#SignedProperties" URI="#'.$signedPropertiesId.'">'
            .'<ds:DigestMethod Algorithm="'.self::NS_DS.'sha1"/>'
            .'<ds:DigestValue>'.$digestSignedProperties.'</ds:DigestValue>'
            .'</ds:Reference>'
            .'<ds:Reference URI="#'.$keyInfoId.'">'
            .'<ds:DigestMethod Algorithm="'.self::NS_DS.'sha1"/>'
            .'<ds:DigestValue>'.$digestKeyInfo.'</ds:DigestValue>'
            .'</ds:Reference>'
            .'<ds:Reference Id="'.$comprobanteRefId.'" URI="#comprobante">'
            .'<ds:Transforms><ds:Transform Algorithm="'.self::NS_DS.'enveloped-signature"/></ds:Transforms>'
            .'<ds:DigestMethod Algorithm="'.self::NS_DS.'sha1"/>'
            .'<ds:DigestValue>'.$digestComprobante.'</ds:DigestValue>'
            .'</ds:Reference>'
            .'</ds:SignedInfo>';

        $signature = '';
        if (! openssl_sign($this->canonicalize($signedInfoXml), $signature, $privateKeyPem, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('Failed to compute the RSA-SHA1 signature.');
        }
        $signatureValue = base64_encode($signature);

        $signatureXml = '<ds:Signature xmlns:ds="'.self::NS_DS.'" xmlns:etsi="'.self::NS_ETSI.'" Id="'.$sigId.'">'
            .$signedInfoXml
            .'<ds:SignatureValue Id="'.$signatureValueId.'">'.$signatureValue.'</ds:SignatureValue>'
            .$keyInfoXml
            .'<ds:Object Id="'.$objectId.'"><etsi:QualifyingProperties Target="#'.$sigId.'">'.$signedPropertiesXml.'</etsi:QualifyingProperties></ds:Object>'
            .'</ds:Signature>';

        $insertAt = strrpos($xml, '</factura>');
        if ($insertAt === false) {
            throw new RuntimeException('Cannot locate the closing </factura> tag to append the signature.');
        }

        return substr_replace($xml, $signatureXml, $insertAt, 0);
    }

    /**
     * @return array{0: string, 1: string} [certificate PEM, private key PEM]
     */
    private function loadKeyPair(CompanyCertificate $certificate): array
    {
        if (! Storage::disk('local')->exists($certificate->path)) {
            throw new RuntimeException("Certificate file not found at {$certificate->path}.");
        }

        $password = Crypt::decryptString($certificate->password_encrypted);
        $parsed = Pkcs12Reader::read(Storage::disk('local')->path($certificate->path), $password);

        return [$parsed['cert'], $parsed['pkey']];
    }

    private function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem) ?? '';

        return base64_decode($body);
    }

    private function canonicalize(string $xml): string
    {
        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        if (! $document->loadXML($xml)) {
            throw new RuntimeException('Failed to parse XML fragment for canonicalization.');
        }

        return $document->documentElement->C14N();
    }

    private function digest(string $bytes): string
    {
        return base64_encode(sha1($bytes, true));
    }
}
