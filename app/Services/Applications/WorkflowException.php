<?php

namespace App\Services\Applications;

use RuntimeException;

/** A rule of the approval-first workflow was violated. The message is safe to show to the owner. */
class WorkflowException extends RuntimeException {}
