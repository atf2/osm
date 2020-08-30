<?php
declare(strict_types=1);
namespace atf\OSM;
use PHPUnit\Framework\TestCase;

//define( 'TRACEOSM', '/getIndividual/' );

include_once "config.tests.php";

class ScoutTest extends TestCase
{   private $ex;
    private static $sharedOSM;
    private $osm;
    private $scout;
    
    public static function setUpBeforeClass(): void
    {
      self::$sharedOSM = new OSM( OSM_API_ID_VALID, OSM_API_TOKEN_VALID );
      self::$sharedOSM->Login( OSM_LOGIN_VALID, OSM_PASSWORD_VALID );
    }
    
    public function setUp(): void
    { $this->osm = self::$sharedOSM;
      global $examples;
      $this->ex = $examples['Scout'];
      $this->scout = $this->osm->FindScoutByName( $this->ex['firstName'],
                                                  $this->ex['lastName'] );
      $this->assertInstanceOf( Scout::class, $this->scout );
    }

    public function testBadgeWork() {
      $badgeWorks = $this->scout->AllBadgeWork();
      $this->assertIsArray( $badgeWorks, "AllBadgeWork should return an array" );
      foreach ($badgeWorks as $badgeWork) {
          $this->assertInstanceOf( BadgeWork::class, $badgeWork, "AllBadgeWork should return BadgeWorks" );
      }
    }
    
    public function testExample() {
      $scout = $this->scout;
      foreach ($this->ex as $k => $v) {
        switch ($k) {
          case 'id':
            $this->assertIsInt( $scout->id );
            if ($v !== null)
              $this->assertSame( $v, $scout->id, "Scout Id" );
            break;
          case 'patrolLevelName':
            $this->assertEquals( $v, $scout->PatrolLevelName(), 'Patrol level name' );
            break;
          case 'patrolName':
            $this->assertEquals( $v, $scout->PatrolName(), 'Patrol name' );
            break;
          case  'extras':
            foreach ($v as $key => $value) {
              $this->assertEquals( $value, $scout->getExtra( $key ), "Scout Extra $key" );
            }
            break;
          default:
            if (is_array($v) && is_object( $scout->$k )) {
              $contact = $scout->$k;
              foreach ($v as $contactField => $contactValue) {
                if ($contactField == 'extras') {
                  foreach ($contactValue as $extraField => $extraValue) {
                    $this->assertEquals( $extraValue,
                                         $contact->getExtra( $extraField ), "Scout $k contact extra $extraField" );
                  }
                } elseif ($contactValue !== null) {
                  $this->assertEquals( $contactValue, $contact->$contactField, "Scout $k contact->$contactField" );
                }
              }
            }
            elseif ($v !== null) $this->assertEquals( $v, $scout->$k, "Scout property $k" );
        }
      }
    }

    public static function tearDownAfterClass(): void {
      self::$sharedOSM->PrintAPIUsage();
    }
}
