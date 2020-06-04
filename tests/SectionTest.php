<?php
declare(strict_types=1);
namespace atf\OSM;
use PHPUnit\Framework\TestCase;

include_once "config.tests.php";
$_GET['traceosm'] = "/.*/";

class SectionTest extends TestCase
{
    private static $sharedOSM;
    private $osm;
    
    public static function setUpBeforeClass(): void
    {
        self::$sharedOSM = new OSM( OSM_API_ID_VALID, OSM_API_TOKEN_VALID );
        self::$sharedOSM->Login( OSM_LOGIN_VALID, OSM_PASSWORD_VALID );
    }
    
    public function setUp(): void
    { $this->osm = self::$sharedOSM;
      $this->section = $this->osm->section;
      $this->assertInstanceOf( Section::class, $this->section );
    }

    public function testEvents() {
        $events = $this->section->Events();
        $this->assertIsArray( $events, "Events should return an array" );
        foreach ($events as $event) {
          $this->assertInstanceOf( Event::class, $event, "Events should return Events" );
        }
    }
    
    public function testExample() {
      global $examples;
      $ex = $examples['Section'];
      $section = $this->osm->Section( $ex['sectionid'] );
      foreach ($ex as $k => $v) {
        switch ($k) {
          case 'permissions':
            foreach ($v as $pk => $pv) {
              $this->assertSame( $pv, $section->UserPermissions( $pk ),
                                 "User permission $pk mis-match" );
            }
            break;
          case 'sectionid':
            $this->assertSame( $v, $section->id, "Section Id" );
            break;
          case 'sectionname':
            $this->assertSame( $v, $section->name, "Section Name" );
            break;
          default:
            $this->assertEquals( $v, $section->$k, "Section property $k" );
        }
      }
    }

    public static function tearDownAfterClass(): void {
      self::$sharedOSM->PrintAPIUsage();
    }
}
