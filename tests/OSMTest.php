<?php
declare(strict_types=1);
namespace atf\OSM;
use PHPUnit\Framework\TestCase;

include_once "config.tests.php";

class OSMTest extends TestCase
{
    private static $osm;
    
    public static function setUpBeforeClass() : void
    {
        //phpinfo();
        self::assertTrue( defined( 'OSM_API_ID_VALID' ), "Define OSM_API_ID_VALID in config.tests.php" );
        self::assertTrue( defined( 'OSM_API_TOKEN_VALID' ), "Define OSM_API_TOKEN_VALID in config.tests.php" );
        self::$osm = new OSM( OSM_API_ID_VALID, OSM_API_TOKEN_VALID );
        self::assertInstanceOf( OSM::class, self::$osm, "new OSM doesn't return an instance of class OSM" );
    }

    public function testLogin() {
        self::$osm->Logout();
        $this->assertNull( self::$osm->IsLoggedIn(), "Still logged in after Logout()" );
        $this->assertTrue( defined( 'OSM_LOGIN_VALID' ) );
        $this->assertTrue( defined( 'OSM_PASSWORD_VALID' ) );
        self::$osm->Login( OSM_LOGIN_VALID, OSM_PASSWORD_VALID );
        $this->assertIsString( self::$osm->IsLoggedIn(), "Failed to log in" );
        self::$osm->PrintAPIUsage();
    }
}