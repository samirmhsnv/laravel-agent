<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Exceptions;

use LogicException;

/** A programming error in a tool or DTO class (missing attribute, unsupported type...). */
class InvalidToolDefinition extends LogicException {}
