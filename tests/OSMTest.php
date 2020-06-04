<?php
declare(strict_types=1);
namespace atf\OSM;
use PHPUnit\Framework\TestCase;

include_once "config.tests.php";
//$_GET['traceosm'] = "/getNextThings/";

class OSMTest extends TestCase
{
    private static $osm;
    private static $login;
    
    public static function setUpBeforeClass(): void
    {
        //phpinfo();
        self::assertTrue( defined( 'OSM_API_ID_VALID' ), "Define OSM_API_ID_VALID in config.tests.php" );
        self::assertTrue( defined( 'OSM_API_TOKEN_VALID' ), "Define OSM_API_TOKEN_VALID in config.tests.php" );
        self::$osm = new OSM( OSM_API_ID_VALID, OSM_API_TOKEN_VALID );
        self::assertInstanceOf( OSM::class, self::$osm, "new OSM doesn't return an instance of class OSM" );
        self::assertTrue( defined( 'OSM_LOGIN_VALID' ), "Define OSM_LOGIN_VALID in config.tests.php" );
        self::assertTrue( defined( 'OSM_PASSWORD_VALID' ), "Define OSM_PASSWORD_VALID in config.tests.php" );
        self::$osm->Login( OSM_LOGIN_VALID, OSM_PASSWORD_VALID );
        self::$login = OSM_LOGIN_VALID;
    }

    public function testLogin() {
        self::$osm->Logout();
        $this->assertNull( self::$osm->IsLoggedIn(), "Still logged in after Logout()" );
        self::$osm->Login( OSM_LOGIN_VALID, OSM_PASSWORD_VALID );
        $this->assertIsString( self::$osm->IsLoggedIn(), "Failed to log in" );
    }
    
    public function testMyChildren() {
      $children = self::$osm->myChildren;
      $this->assertIsArray( $children );
      foreach ($children as $child) {
        $this->assertInstanceOf( Scout::class, $child );
      }
    }
    
    public function testLoggedInAsLeader() {
      $this->assertTrue( self::$osm->IsLoggedInAsLeader() );
    }

    public function testPropertySection() {
      $section = self::$osm->section;
      $this->assertInstanceOf( Section::class, $section );
    }
    
    public function testSection() {
      $section = self::$osm->Section( self::$osm->section->id );
      $this->assertSame( $section, self::$osm->section, "Section created duplicate object" );
    }
    
    public function testSetSection() {
      $section = $defaultSection = self::$osm->SetSection();
      foreach (self::$osm->sections as $mySection) {
        if ($section !== $mySection) {
          $section = self::$osm->SetSection( $mySection );
          $this->assertSame( self::$osm->section, $mySection, "SetSection didn't" );
          $this->assertSame( $section, $mySection, "SetSection didn't return arg" );
          break;
        }
      }
      $section = self::$osm->SetSection();
      $this->assertSame( self::$osm->section, $defaultSection, "SetSection didn't reset to default" );
      $this->assertSame( $section, $defaultSection, "SetSection didn't return default" );
    }

    public function testPropertySections() {
      $sections = self::$osm->sections;
      $this->assertIsArray( $sections );
      $this->assertGreaterThan( 0, count( $sections ), "No sections accessible to ". self::$login );
      foreach ($sections as $section) {
        $this->assertInstanceOf( Section::class, $section );
      }
    }

    public static function tearDownAfterClass(): void {
      self::$osm->PrintAPIUsage();
    }
}
