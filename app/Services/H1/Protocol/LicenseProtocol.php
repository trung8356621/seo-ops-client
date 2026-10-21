<?php

declare(strict_types=1);

namespace App\Services\H1\Protocol;

final class LicenseProtocol
{
    public const ID = 'seo-ops-license/1';

    public const PRODUCT = 'seo-ops';

    public const SIGNATURE_PREFIX = 'v1=';

    public const HEADER_PROTOCOL = 'X-Ops-Protocol';

    public const HEADER_CREDENTIAL_ID = 'X-Ops-Credential-Id';

    public const HEADER_TIMESTAMP = 'X-Ops-Timestamp';

    public const HEADER_NONCE = 'X-Ops-Nonce';

    public const HEADER_CONTENT_SHA256 = 'X-Ops-Content-SHA256';

    public const HEADER_SIGNATURE = 'X-Ops-Signature';

    public const EMPTY_BODY_SHA256 = '47DEQpj8HBSa-_TImW-5JCeuQeRkm5NMpJWZG3hSuFU';
}
