<?php

namespace App\Services\Seo\Exceptions;

use RuntimeException;

class AllProvidersFailedException extends RuntimeException
{
    public function __construct(string $message = 'All LLM providers failed; falling back to template engine.')
    {
        parent::__construct($message);
    }
}
