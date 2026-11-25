<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabEntry;

use PeakSim\Runtime\LabEntry\LoginChoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who the lab's entry logs in as (LoginChoice). The BookStack lab offers no list, and there the entry must answer exactly as it
 * always did: the default login, or nobody for `X-Lab-Login: none`, whatever else the header says.
 */
final class LoginChoiceTest extends TestCase
{
    private const DEFAULT = 'BookStack\\Users\\Models\\User:1:web';

    private const CUSTOMER = 'Shop\\Customer:1:customer';

    private const ADMIN = 'Shop\\Admin:1:admin';

    /** @return iterable<string, array{string, string}> header, answer, with no list on offer */
    public static function withNoListOffered(): iterable
    {
        yield 'no header: the default login' => ['', self::DEFAULT];
        yield 'none: nobody' => ['none', ''];
        yield 'a name: still the default, as before' => ['admin', self::DEFAULT];
        yield 'something else: still the default, as before' => ['Customer:1:customer', self::DEFAULT];
    }

    #[DataProvider('withNoListOffered')]
    public function testWithNoListOfferedTheEntryAnswersAsItAlwaysDid(string $header, string $expected): void
    {
        self::assertSame($expected, LoginChoice::resolve($header, self::DEFAULT, []));
    }

    public function testAnEmptyListIsNoList(): void
    {
        self::assertSame([], LoginChoice::parseOffered(''));
        self::assertSame([], LoginChoice::parseOffered(" ; \n;"));
    }

    public function testAListNamesTheLoginsOfTheApp(): void
    {
        $offered = LoginChoice::parseOffered('customer=' . self::CUSTOMER . '; admin=' . self::ADMIN . ';');
        self::assertSame(['customer' => self::CUSTOMER, 'admin' => self::ADMIN], $offered);
        self::assertSame(self::CUSTOMER, LoginChoice::resolve('customer', self::CUSTOMER, $offered));
        self::assertSame(self::ADMIN, LoginChoice::resolve('admin', self::CUSTOMER, $offered));
        self::assertSame('', LoginChoice::resolve('none', self::CUSTOMER, $offered));
        self::assertSame(self::CUSTOMER, LoginChoice::resolve('', self::CUSTOMER, $offered), 'no header is the default login');
    }

    /** @return iterable<string, array{string}> */
    public static function headersNotOffered(): iterable
    {
        yield 'a name that is not on the list' => ['staff'];
        yield 'a user model and key of the request\'s own' => ['Shop\\Admin:2:admin'];
        yield 'the spec of an offered login' => [self::ADMIN];
        yield 'another case' => ['Admin'];
        yield 'two names' => ['customer,admin'];
    }

    #[DataProvider('headersNotOffered')]
    public function testWithAListOfferedAnotherLoginIsARefusalNeverTheDefault(string $header): void
    {
        $offered = LoginChoice::parseOffered('customer=' . self::CUSTOMER . ';admin=' . self::ADMIN);
        $this->expectException(\InvalidArgumentException::class);
        LoginChoice::resolve($header, self::CUSTOMER, $offered);
    }

    /** @return iterable<string, array{string}> */
    public static function badLists(): iterable
    {
        yield 'no name' => [self::CUSTOMER];
        yield 'no spec' => ['customer='];
        yield 'an upper case name' => ['Customer=' . self::CUSTOMER];
        yield 'the name of the guest' => ['none=' . self::CUSTOMER];
        yield 'a name twice' => ['customer=' . self::CUSTOMER . ';customer=' . self::ADMIN];
        yield 'a spec with no guard' => ['customer=Shop\\Customer:1'];
        yield 'a spec ActingAs cannot read' => ['customer=not a spec'];
        yield 'a space in a spec' => ['customer=Shop\\Customer:1 :customer'];
    }

    #[DataProvider('badLists')]
    public function testAListThatCannotBeReadIsAnError(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LoginChoice::parseOffered($text);
    }

    public function testTheNameOfASpecIsTheNameOnTheList(): void
    {
        $offered = ['customer' => self::CUSTOMER, 'admin' => self::ADMIN];
        self::assertSame('admin', LoginChoice::nameOf(self::ADMIN, $offered));
        self::assertSame('customer', LoginChoice::nameOf(self::CUSTOMER, $offered));
        self::assertNull(LoginChoice::nameOf('Shop\\Admin:2:admin', $offered));
        self::assertNull(LoginChoice::nameOf(self::ADMIN, []));
    }
}
