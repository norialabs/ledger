<?php

declare(strict_types=1);

namespace Noria\Ledger\Tests\Support;

use Noria\Ledger\Tests\TestCase;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\FunctionParameterClosureThisExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

final class PestClosureThis implements FunctionParameterClosureThisExtension
{
    public function isFunctionSupported(FunctionReflection $functionReflection, ParameterReflection $parameter): bool
    {
        return in_array($functionReflection->getName(), ['it', 'test', 'beforeEach', 'afterEach'], true) && $parameter->getName() === 'closure';
    }

    public function getClosureThisTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, ParameterReflection $parameter, Scope $scope): Type
    {
        return new ObjectType(TestCase::class);
    }
}
