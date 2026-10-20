<?php

declare(strict_types=1);

namespace Mk\Director\Help;

use RuntimeException;

/** Un archivo del manual que no se puede indexar: el mensaje dice cuál y por qué. */
final class MkHelpContentException extends RuntimeException {}
