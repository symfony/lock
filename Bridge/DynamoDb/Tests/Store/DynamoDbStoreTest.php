<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Lock\Bridge\DynamoDb\Tests\Store;

use AsyncAws\DynamoDb\DynamoDbClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Bridge\DynamoDb\Store\DynamoDbStore;

class DynamoDbStoreTest extends TestCase
{
    public function testExtraOptions()
    {
        $this->expectException(\InvalidArgumentException::class);
        new DynamoDbStore('dynamodb://default/lock_keys', [
            'extra_key',
        ]);
    }

    public function testExtraParamsInQuery()
    {
        $this->expectException(\InvalidArgumentException::class);
        new DynamoDbStore('dynamodb://default/lock_keys?extra_param=some_value');
    }

    public function testConfigureWithCredentials()
    {
        $awsKey = 'some_aws_access_key_value';
        $awsSecret = 'some_aws_secret_value';
        $region = 'us-east-1';
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => $region, 'accessKeyId' => $awsKey, 'accessKeySecret' => $awsSecret]), ['table_name' => 'lock_keys']),
            new DynamoDbStore('dynamodb://default/lock_keys', [
                'access_key' => $awsKey,
                'secret_key' => $awsSecret,
                'region' => $region,
            ])
        );
    }

    public function testConfigureWithTemporaryCredentials()
    {
        $awsKey = 'some_aws_access_key_value';
        $awsSecret = 'some_aws_secret_value';
        $sessionToken = 'some_aws_sessionToken';
        $region = 'us-east-1';
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => $region, 'accessKeyId' => $awsKey, 'accessKeySecret' => $awsSecret, 'sessionToken' => $sessionToken]), ['table_name' => 'table']),
            new DynamoDbStore('dynamodb://default/table', [
                'access_key' => $awsKey,
                'secret_key' => $awsSecret,
                'session_token' => $sessionToken,
                'region' => $region,
            ])
        );
    }

    public function testFromInvalidDsn()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The given Amazon DynamoDB DSN is invalid.');

        new DynamoDbStore('dynamodb://');
    }

    public function testFromUnsupportedDsn()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported DSN for DynamoDB.');

        new DynamoDbStore('unsupported://');
    }

    public function testFromDsn()
    {
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => null, 'accessKeyId' => null, 'accessKeySecret' => null]), ['table_name' => 'table']),
            new DynamoDbStore('dynamodb://default/table', [])
        );
    }

    public function testDsnPrecedence()
    {
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => 'us-east-2', 'accessKeyId' => 'key_dsn', 'accessKeySecret' => 'secret_dsn']), ['table_name' => 'table_dsn']),
            new DynamoDbStore('dynamodb://key_dsn:secret_dsn@default/table_dsn?region=us-east-2', ['region' => 'eu-west-3', 'table_name' => 'table_options', 'access_key' => 'key_option', 'secret_key' => 'secret_option'])
        );
    }

    public function testFromDsnWithRegion()
    {
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => 'us-west-2', 'accessKeyId' => null, 'accessKeySecret' => null]), ['table_name' => 'table']),
            new DynamoDbStore('dynamodb://default/table?region=us-west-2', [])
        );
    }

    public function testFromDsnWithCustomEndpoint()
    {
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => null, 'endpoint' => 'https://localhost', 'accessKeyId' => null, 'accessKeySecret' => null]), ['table_name' => 'table']),
            new DynamoDbStore('dynamodb://localhost/table', [])
        );
    }

    public function testFromDsnWithSslMode()
    {
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => null, 'endpoint' => 'http://localhost', 'accessKeyId' => null, 'accessKeySecret' => null]), ['table_name' => 'table']),
            new DynamoDbStore('dynamodb://localhost/table?sslmode=disable', [])
        );
    }

    public function testFromDsnWithSslModeOnDefault()
    {
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => null, 'accessKeyId' => null, 'accessKeySecret' => null]), ['table_name' => 'table']),
            new DynamoDbStore('dynamodb://default/table?sslmode=disable', [])
        );
    }

    public function testFromDsnWithCustomEndpointAndPort()
    {
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => null, 'endpoint' => 'https://localhost:1234', 'accessKeyId' => null, 'accessKeySecret' => null]), ['table_name' => 'table']),
            new DynamoDbStore('dynamodb://localhost:1234/table', [])
        );
    }

    public function testFromDsnWithQueryOptions()
    {
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => null, 'accessKeyId' => null, 'accessKeySecret' => null]), ['table_name' => 'table', 'id_attr' => 'id_dsn']),
            new DynamoDbStore('dynamodb://default/table?id_attr=id_dsn', [])
        );
    }

    public function testFromDsnWithTableNameOption()
    {
        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => null, 'accessKeyId' => null, 'accessKeySecret' => null]), ['table_name' => 'table']),
            new DynamoDbStore('dynamodb://default', ['table_name' => 'table'])
        );

        $this->assertEquals(
            new DynamoDbStore(new DynamoDbClient(['region' => null, 'accessKeyId' => null, 'accessKeySecret' => null]), ['table_name' => 'table']),
            new DynamoDbStore('dynamodb://default/table', ['table_name' => 'table_ignored'])
        );
    }

    public function testFromDsnWithInvalidQueryString()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('|Unknown option found in DSN: \[foo\]\. Allowed options are \[access_key, |');

        new DynamoDbStore('dynamodb://default?foo=foo');
    }

    public function testFromDsnWithInvalidOption()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('|Unknown option found: \[bar\]\. Allowed options are \[access_key, |');

        new DynamoDbStore('dynamodb://default', ['bar' => 'bar']);
    }

    public function testFromDsnWithInvalidQueryStringAndOption()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('|Unknown option found: \[bar\]\. Allowed options are \[access_key, |');

        new DynamoDbStore('dynamodb://default?foo=foo', ['bar' => 'bar']);
    }
}
