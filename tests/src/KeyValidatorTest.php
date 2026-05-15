<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Cache\Exception\InvalidCacheKeyException;
use Waffle\Commons\Cache\KeyValidator;

#[CoversClass(KeyValidator::class)]
final class KeyValidatorTest extends AbstractTestCase
{
    public function testAcceptsSimpleAlphanumericKey(): void
    {
        KeyValidator::assertValid('user.42');
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        $this->expectExceptionMessage('must not be empty');
        KeyValidator::assertValid('');
    }

    /** @return iterable<string, array{0: string}> */
    public static function reservedCharProvider(): iterable
    {
        yield 'brace-open'  => ['user{42}'];
        yield 'paren'       => ['user(42)'];
        yield 'forward-slash' => ['user/42'];
        yield 'backslash'   => ['user\\42'];
        yield 'at-sign'     => ['user@host'];
        yield 'colon'       => ['user:42'];
    }

    #[DataProvider('reservedCharProvider')]
    public function testRejectsReservedCharacters(string $key): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        $this->expectExceptionMessage('reserved characters');
        KeyValidator::assertValid($key);
    }

    public function testRejectsExcessivelyLongKey(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        $this->expectExceptionMessage('exceeds');
        KeyValidator::assertValid(str_repeat('a', 65));
    }

    public function testAssertValidAllReturnsValidatedList(): void
    {
        $keys = KeyValidator::assertValidAll(['a', 'b', 'c.d']);
        static::assertSame(['a', 'b', 'c.d'], $keys);
    }

    public function testAssertValidAllRejectsNonStringKey(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        $this->expectExceptionMessage('must be a string');
        KeyValidator::assertValidAll(['ok', 42]);
    }
}
