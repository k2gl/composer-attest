<?php

declare(strict_types=1);

namespace K2gl\ComposerAttest\Exception;

use RuntimeException;

/** A package failed attestation verification (or a required attestation was missing). */
final class AttestationException extends RuntimeException {}
