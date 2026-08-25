<?php

namespace App\DTOs\Products;

final readonly class ProductImportResultData
{
    /**
     * @param  array<int, array{row:int,messages:array<int,string>}>  $errors
     */
    public function __construct(
        public int $validCount,
        public int $invalidCount,
        public array $errors,
    ) {
    }
}
