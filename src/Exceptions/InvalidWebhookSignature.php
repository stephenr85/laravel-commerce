<?php

namespace Rushing\Commerce\Exceptions;

use RuntimeException;

/** A provider webhook whose signature does not verify (wrong secret, tampered body, or outside the tolerance window). */
class InvalidWebhookSignature extends RuntimeException {}
