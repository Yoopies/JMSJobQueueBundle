<?php

namespace JMS\JobQueueBundle\Tests\Entity\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use JMS\JobQueueBundle\Entity\Type\SafeObjectType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

class SafeObjectTypeTest extends TestCase
{
    private SafeObjectType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new SafeObjectType();
        $this->platform = new MySQLPlatform();
    }

    public function testColumnStaysABlobSoThatExistingInstallationsNeedNoMigration()
    {
        $this->assertSame('LONGBLOB', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testRoundTripsAFlattenException()
    {
        $exception = FlattenException::create(new \RuntimeException('boom', 0, new \LogicException('inner')));

        $stored = $this->type->convertToDatabaseValue($exception, $this->platform);

        $this->assertJson($stored);
        $this->assertSame($exception->toArray(), $this->type->convertToPHPValue($stored, $this->platform));
    }

    public function testReadsABlobHandedBackAsAStreamResource()
    {
        $exception = FlattenException::create(new \RuntimeException('boom'));
        $stored = $this->type->convertToDatabaseValue($exception, $this->platform);

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $stored);
        rewind($handle);

        $this->assertSame($exception->toArray(), $this->type->convertToPHPValue($handle, $this->platform));
    }

    public function testReadsRowsWrittenBeforeTheSwitchToJson()
    {
        $exception = FlattenException::create(new \RuntimeException('boom', 0, new \LogicException('inner')));

        $this->assertSame(
            $exception->toArray(),
            $this->type->convertToPHPValue(serialize($exception), $this->platform)
        );
    }

    /**
     * Jobs that ended without an exception used to be stored as serialize(null),
     * which must not be mistaken for a trace that failed to decode.
     */
    public function testReadsALegacyRowHoldingNoException()
    {
        $this->assertNull($this->type->convertToPHPValue('N;', $this->platform));
    }

    /**
     * @dataProvider provideUndecodableValues
     */
    public function testReportsUndecodableTracesAsFalse($value)
    {
        $this->assertFalse($this->type->convertToPHPValue($value, $this->platform));
    }

    public function provideUndecodableValues()
    {
        $serialized = serialize(FlattenException::create(new \RuntimeException('boom')));

        // A payload naming a class that no longer exists.
        $renamed = preg_replace_callback(
            '/^O:(\d+):"([^"]+)"/',
            static function (array $m) {
                $class = str_replace('ErrorHandler', 'Debug', $m[2]);

                return 'O:'.strlen($class).':"'.$class.'"';
            },
            $serialized
        );

        return [
            'class that no longer exists' => [$renamed],
            'truncated payload' => [substr($serialized, 0, 40)],
            'malformed json' => ['{not json'],
        ];
    }

    /**
     * @dataProvider provideEmptyValues
     */
    public function testReadsEmptyValuesAsNull($value)
    {
        $this->assertNull($this->type->convertToPHPValue($value, $this->platform));
    }

    public function provideEmptyValues()
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    public function testWritesNullUnchanged()
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    /**
     * DBAL 3 still declares Type::getName() abstract, so dropping it makes the
     * class unloadable there even though DBAL 4 no longer needs it.
     */
    public function testExposesItsDbalTypeName()
    {
        $this->assertSame('jms_job_safe_object', $this->type->getName());
    }
}
