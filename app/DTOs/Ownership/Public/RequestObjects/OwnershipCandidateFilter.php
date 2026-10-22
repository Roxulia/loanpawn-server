<?php

namespace App\DTOs\Ownership\Public\RequestObjects;

/** Bounded receipt candidates, or piece candidates for one selected public receipt-line code. */
final class OwnershipCandidateFilter extends OwnershipPageFilter
{
    public function __construct(
        int $page = 1,
        int $perPage = 25,
        public ?string $search = null,
        public ?string $purchaseReceiptLineCode = null,
    ) {
        // Selection identifies candidates only; services must verify lot/purchase/item association.
        parent::__construct($page, $perPage);
        $this->search = self::search($search);
        $this->purchaseReceiptLineCode = self::code($purchaseReceiptLineCode);
    }

    public static function fromValidated(array $data): static
    {
        // Retain only public candidate search, receipt selection, and pagination.
        return new self(
            page: (int) ($data['page'] ?? 1), perPage: (int) ($data['per_page'] ?? 25),
            search: $data['search'] ?? null, purchaseReceiptLineCode: $data['purchase_receipt_line_code'] ?? null,
        );
    }
}
