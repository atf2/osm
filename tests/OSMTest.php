<?php
declare(strict_types=1);
namespace atf\OSM;
use PHPUnit\Framework\TestCase;

include_once "config.tests.php";
//$_GET['traceosm'] = "/getNextThings/";

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
        self::$osm->Login( OSM_LOGIN_VALID, OSM_PASSWORD_VALID );
    }

    public function testLogin() {
        self::$osm->Logout();
        $this->assertNull( self::$osm->IsLoggedIn(), "Still logged in after Logout()" );
        $this->assertTrue( defined( 'OSM_LOGIN_VALID' ) );
        $this->assertTrue( defined( 'OSM_PASSWORD_VALID' ) );
        self::$osm->Login( OSM_LOGIN_VALID, OSM_PASSWORD_VALID );
        $this->assertIsString( self::$osm->IsLoggedIn(), "Failed to log in" );
    }
    
    public function testMyChildren() {
      $children = self::$osm->MyChildren();
      $this->assertIsArray( $children );
      foreach ($children as $child) {
        $this->assertInstanceOf( Scout::class, $child );
      }
    }
    
    public function testLoggedInAsLeader() {
      $this->assertTrue( self::$osm->IsLoggedInAsLeader() );
    }
    
    public function testSections() {
      $sections = self::$osm->Sections();
      $this->assertIsArray( $sections );
      foreach ($sections as $section) {
        $this->assertInstanceOf( Section::class, $section );
      }
      self::$osm->PrintAPIUsage();
    }
}
