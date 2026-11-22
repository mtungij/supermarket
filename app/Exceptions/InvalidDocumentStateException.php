<?php

namespace App\Exceptions;

/** Illegal status transition or a document that was already processed. */
class InvalidDocumentStateException extends BusinessRuleException {}
