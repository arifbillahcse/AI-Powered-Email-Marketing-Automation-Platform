<?php

namespace App\Services\Ai;

interface TextGenerator
{
    /**
     * @throws AiException
     */
    public function generate(string $system, string $user): AiResult;
}
