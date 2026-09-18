<?php

/**
 * Generates the mock WSDL text, with the soap:address location pointed at
 * this run's ad-hoc local server. Mirrors the real SRI WSDL's structure
 * (document/literal *wrapped*, single input/output element per operation)
 * byte-for-byte in shape -- confirmed by downloading and diffing against
 * https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl
 * after an earlier RPC/encoded version of this mock masked a real bug (see
 * RealSriClient's __soapCall comments): document/literal wrapped requires
 * the request/response shapes this file now reproduces, which RPC/encoded
 * did not exercise.
 */
return function (string $location): string {
    return <<<WSDL
<?xml version="1.0"?>
<wsdl:definitions xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:wsdl="http://schemas.xmlsoap.org/wsdl/"
    xmlns:tns="urn:mocksri"
    xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
    name="MockSriService"
    targetNamespace="urn:mocksri">
  <wsdl:types>
    <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:tns="urn:mocksri" elementFormDefault="unqualified" targetNamespace="urn:mocksri">
      <xs:element name="validarComprobante" type="tns:validarComprobante"/>
      <xs:element name="validarComprobanteResponse" type="tns:validarComprobanteResponse"/>
      <xs:element name="autorizacionComprobante" type="tns:autorizacionComprobante"/>
      <xs:element name="autorizacionComprobanteResponse" type="tns:autorizacionComprobanteResponse"/>

      <xs:complexType name="validarComprobante">
        <xs:sequence>
          <xs:element minOccurs="0" name="xml" type="xs:base64Binary"/>
        </xs:sequence>
      </xs:complexType>
      <xs:complexType name="validarComprobanteResponse">
        <xs:sequence>
          <xs:element minOccurs="0" name="RespuestaRecepcionComprobante" type="tns:respuestaSolicitud"/>
        </xs:sequence>
      </xs:complexType>
      <xs:complexType name="respuestaSolicitud">
        <xs:sequence>
          <xs:element minOccurs="0" name="estado" type="xs:string"/>
          <xs:element minOccurs="0" name="comprobantes">
            <xs:complexType>
              <xs:sequence>
                <xs:element maxOccurs="unbounded" minOccurs="0" name="comprobante" type="tns:comprobante"/>
              </xs:sequence>
            </xs:complexType>
          </xs:element>
        </xs:sequence>
      </xs:complexType>
      <xs:complexType name="comprobante">
        <xs:sequence>
          <xs:element minOccurs="0" name="claveAcceso" type="xs:string"/>
          <xs:element minOccurs="0" name="mensajes">
            <xs:complexType>
              <xs:sequence>
                <xs:element maxOccurs="unbounded" minOccurs="0" name="mensaje" type="tns:mensaje"/>
              </xs:sequence>
            </xs:complexType>
          </xs:element>
        </xs:sequence>
      </xs:complexType>
      <xs:complexType name="mensaje">
        <xs:sequence>
          <xs:element minOccurs="0" name="identificador" type="xs:string"/>
          <xs:element minOccurs="0" name="mensaje" type="xs:string"/>
          <xs:element minOccurs="0" name="informacionAdicional" type="xs:string"/>
          <xs:element minOccurs="0" name="tipo" type="xs:string"/>
        </xs:sequence>
      </xs:complexType>

      <xs:complexType name="autorizacionComprobante">
        <xs:sequence>
          <xs:element minOccurs="0" name="claveAccesoComprobante" type="xs:string"/>
        </xs:sequence>
      </xs:complexType>
      <xs:complexType name="autorizacionComprobanteResponse">
        <xs:sequence>
          <xs:element minOccurs="0" name="RespuestaAutorizacionComprobante" type="tns:respuestaAutorizacionComprobante"/>
        </xs:sequence>
      </xs:complexType>
      <xs:complexType name="respuestaAutorizacionComprobante">
        <xs:sequence>
          <xs:element minOccurs="0" name="claveAccesoConsultada" type="xs:string"/>
          <xs:element minOccurs="0" name="numeroComprobantes" type="xs:string"/>
          <xs:element minOccurs="0" name="autorizaciones">
            <xs:complexType>
              <xs:sequence>
                <xs:element maxOccurs="unbounded" minOccurs="0" name="autorizacion" type="tns:autorizacion"/>
              </xs:sequence>
            </xs:complexType>
          </xs:element>
        </xs:sequence>
      </xs:complexType>
      <xs:complexType name="autorizacion">
        <xs:sequence>
          <xs:element minOccurs="0" name="estado" type="xs:string"/>
          <xs:element minOccurs="0" name="numeroAutorizacion" type="xs:string"/>
          <xs:element minOccurs="0" name="fechaAutorizacion" type="xs:string"/>
          <xs:element minOccurs="0" name="ambiente" type="xs:string"/>
          <xs:element minOccurs="0" name="mensajes">
            <xs:complexType>
              <xs:sequence>
                <xs:element maxOccurs="unbounded" minOccurs="0" name="mensaje" type="tns:mensaje"/>
              </xs:sequence>
            </xs:complexType>
          </xs:element>
        </xs:sequence>
      </xs:complexType>
    </xs:schema>
  </wsdl:types>

  <wsdl:message name="validarComprobante">
    <wsdl:part element="tns:validarComprobante" name="parameters"/>
  </wsdl:message>
  <wsdl:message name="validarComprobanteResponse">
    <wsdl:part element="tns:validarComprobanteResponse" name="parameters"/>
  </wsdl:message>
  <wsdl:message name="autorizacionComprobante">
    <wsdl:part element="tns:autorizacionComprobante" name="parameters"/>
  </wsdl:message>
  <wsdl:message name="autorizacionComprobanteResponse">
    <wsdl:part element="tns:autorizacionComprobanteResponse" name="parameters"/>
  </wsdl:message>

  <wsdl:portType name="MockSriPortType">
    <wsdl:operation name="validarComprobante">
      <wsdl:input message="tns:validarComprobante"/>
      <wsdl:output message="tns:validarComprobanteResponse"/>
    </wsdl:operation>
    <wsdl:operation name="autorizacionComprobante">
      <wsdl:input message="tns:autorizacionComprobante"/>
      <wsdl:output message="tns:autorizacionComprobanteResponse"/>
    </wsdl:operation>
  </wsdl:portType>

  <wsdl:binding name="MockSriBinding" type="tns:MockSriPortType">
    <soap:binding style="document" transport="http://schemas.xmlsoap.org/soap/http"/>
    <wsdl:operation name="validarComprobante">
      <soap:operation soapAction="" style="document"/>
      <wsdl:input><soap:body use="literal"/></wsdl:input>
      <wsdl:output><soap:body use="literal"/></wsdl:output>
    </wsdl:operation>
    <wsdl:operation name="autorizacionComprobante">
      <soap:operation soapAction="" style="document"/>
      <wsdl:input><soap:body use="literal"/></wsdl:input>
      <wsdl:output><soap:body use="literal"/></wsdl:output>
    </wsdl:operation>
  </wsdl:binding>

  <wsdl:service name="MockSriService">
    <wsdl:port name="MockSriPort" binding="tns:MockSriBinding">
      <soap:address location="{$location}"/>
    </wsdl:port>
  </wsdl:service>
</wsdl:definitions>
WSDL;
};
