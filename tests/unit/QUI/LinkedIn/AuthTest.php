<?php

namespace QUI\LinkedIn;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AuthTest extends TestCase
{
    public function testAuthDeclaresBooleanResult(): void
    {
        $returnType = (new ReflectionMethod(Auth::class, 'auth'))->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame('bool', $returnType->getName());
    }
}
