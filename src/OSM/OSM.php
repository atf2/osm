<?php
/** The following collection of classes provide straitforward access to various
 * parts of the Online Scout Manager software hosted at
 * www.onlinescoutmanager.co.uk.
 *
 * Note that the author has no relationship, other than as a customer, with the
 * owner of Online Scout Manager and that this code is provided as-is with no
 * guarantee of accuracy or correctness.
 
 */
declare(strict_types=1);
namespace atf\OSM;

/* The comment below contains pretty much the only help provided by OSM on how
 * to use their API.  It is reproduced here for reference.
 *
 * There is no documentation for the API.  Instead, please use a developer
 * console to see what you should be requesting (with associated POST/GET
 * parameters) and what you'll get back.  i.e. find a page with data that you
 * want to use and then look at the requests to see how to call it.
 * 
 * Please note, there is a difference between GET and POST - you must always use
 * the right type.
 *
 * There is one exception to make your life easier!  When getting badge records,
 * the JSON for OSM is split into two chunks, one bit for the name (which is
 * always shown) and another for the records.  The API version gives this to you
 * in one bigger chunk,  much easier!
 * 
 * It is safe to ignore all _csrf POST parameters - they are only used by
 * website logins.
 * 
 * There are a few secret functions to give you access to bits that you won't
 * see called via AJAX on OSM:
 * https://www.onlinescoutmanager.co.uk/api.php?action=getUserRoles
 * https://www.onlinescoutmanager.co.uk/api.php?action=getSectionConfig
 * https://www.onlinescoutmanager.co.uk/api.php?action=getTerms
 * https://www.onlinescoutmanager.co.uk/api.php?action=getNotepads
 * 
 */

/** Base class to force errors if an undeclared property is used.
 */
class BaseObject
{ 
  /** Called when any attempt is made to set an undeclared property; this method
   * will cause an error message and an exception.
   * PHP will complain about reading undefined properties or executing undefined
   * methods, so we don't need to define a magic method for those.
   */
  public function __set( $name, $v ) {
    $trace = debug_backtrace();
    $caller = $trace[0];
    echo "Attempt to set ", get_class($this), "->$name in line {$caller['line']} of {$caller['file']}<br/>\n";
    throw new \Exception( "Attempt to set " . get_class($this) . "->$name" );
  }
}

class OSM extends BaseObject
{
	const BADGETYPE_CHALLENGE = "challenge";
	const BADGETYPE_STAGED = "staged";
	const BADGETYPE_ACTIVITY = "activity";
	
	/* @var int  Anyone using OSM through their web API must be issued an ID by
   *           OSM support.  This property stores to API Id to be used in
   *           accessing the API.
	 */
	public $apiId = 0;

  /** @var Badge[]  array of badges known to this session, indexed by
   *           combination of Badge Id and badge version.  This array is used to
   * cache badges, avoiding the creation of multiple objects for a single badge.
   */
  private $badges = array();
  
  /** Handle used for Curl operations on OSM API
   */
  private $curlHandle = null;

  /** @var Section|null  the current section in OSM.  This will be initialised to the section their
   *           use was last using in the OSM web interface.  Changes to this will not be reflected
   *           in the OSM web interface. */
  private $currentSection = null;
  
  /** @var string|null  the email used to log in to OSM, or null if not logged in. */
  private $email = null;
  
  public $errorCode = '';
  
  public $errorMessage = '';
  
  /** @var null|Section[]  array of sections to which the logged-in user has
   *           leader access, or null if the API has not been interrogated (by
   *           method Sections).  Note the sections containing the users own
   *           children will not be included unless the user also has leader
   *           access to those sections)
   */
  private $leaderSections = null;
  
  /* @var null|Scout[]  array containing logged-in user's children, or null if
   *           the API has not been interrogated (by method MyChildren) to
   *           discover this.  For leaders who are not also parents this will be
   *           an empty array. */
  private $myChildren = null;
  
  /** @var Section[] Array of sections known to this instance, indexed by the section identifier
   * used by the API.  This list is not exhaustive and may be added to if method FindSection is
   * called with the $create parameter set.
   * Contrast with property $leaderSections.
   */
  private $sections = array();
	
	/** The API Token issued by OSM support when they first authorise use of the API.
	 * This may be specified as a constant OSM_TOKEN or may be given as an argument to the OSM
   * constructor.
	 * @var string
	 */
	private $token;
	
	/**
	 * The user ID, obtained through authorize()
	 * 
	 * @var int
	 */
	private $userId = null;
	
	/**
	 * The user secret, obtained through authorize()
	 * 
	 * @var string
	 */
	private $secret = null;
	
	/**
	 * The absolute URL which all URLs are relative to.
	 * 
	 * @var string
	 */
	private $base = 'https://www.onlinescoutmanager.co.uk/';
	
	/** Constructor for an OSM object which can be used to access Online Scout Manager
   *
	 * @param number $apiid API ID, as supplied by OSM support when authorising an application.  If
   *           this parameter is omitted, the constant OSM_API_ID, if defined, will be used instead.
	 * @param string $token Token, as supplied by OSM support when authorising an application.  If
   *           this parameter is omitted, the constant OSM_TOKEN, if defined, will be used instead.
	 */
	public function __construct( string $apiId = null, string $token = null )
  {	// Establish what API Id and Token are to be used to access OSM
    if ($apiId == null && defined( 'OSM_API_ID' )) $apiId = OSM_API_ID;
    $this->apiId = $apiId;
		if ($token == null && defined( 'OSM_TOKEN' )) $token = OSM_TOKEN;
    $this->token = $token;
    if (!$this->apiId) throw new \Exception( "OSM API id not specified" );
    if (!$this->token) throw new \Exception( "OSM token not specified" );
    
    // Retrieve result of previous authorisation if it is present in the session
    // PHP_SAPI check to protect PHPUnit tests, but may need more work
    if (PHP_SAPI !== 'cli') {
      if (!session_id()) session_start();
    } else {
      if (!isset( $_SESSION )) $_SESSION = array();
    }
    if (!isset( $_SESSION['OSM_EXPIRES'] ) || time() > $_SESSION['OSM_EXPIRES'])
      unset( $_SESSION['OSM_USERID'] );
    $_SESSION['OSM_EXPIRES'] = time() + 30 * 60; // In half an hour
    if (isset( $_SESSION['OSM_USERID'] ) && isset( $_SESSION['OSM_SECRET'] ))
    { $this->userId = $_SESSION['OSM_USERID'];
    	$this->secret = $_SESSION['OSM_SECRET'];
      $this->email  = $_SESSION['OSM_EMAIL'];
	  }
	}
  
  public function ClearCache() {
    $this->leaderSections = null;
    $this->myChildren = null;
    $this->sections = array();
  }

  /** Get the default section
   *
   * @return Section|null
  */
  public function CurrentSection( int $sectionId = null ) {
    if (!$this->sections) $this->Sections();
    if ($sectionId) {
      foreach ($this->sections as $section) {
        if ($section->id == $sectionId) {
          $this->currentSection = $section;
        }
      }
    }
    return $this->currentSection;
  }

  /** Get the current term of the default section
   *
   * @return Term
  */
  public function DefaultTerm()
  { $section = $this->currentSection();
    return $section->TermAt();
  }

  /** Finds the object representing a particular badge and version, creating the object if necessary
   * but leaving most properties undefined. */
  public function FindBadge( $idv ) {
    if (!isset( $this->badges[$idv] ))
      $this->badges[$idv] = new Badge( $idv );
    return $this->badges[$idv];
  }

  /** Given a section Id, (creates and) returns the Section object for that section.
   *
   * @param int $sectionId  the number used in OSM as the unique identifier for a section.
   * @param bool $create  normally, this method will return an Section object if one has already
   *           been created, or null if that section hasn't been seen on this run.  If $create is
   *           true then an Section object will be created if necessary (although no attempt is
   *           made to create a section in OSM itself).
   *
   * @returns Section|null the object representing the section, or null if it was not found.
   */
  public function FindSection( int $sectionId, bool $create = false ) {
    if (!isset( $this->sections[ $sectionId ])) {
      if (!$create) return null;
      $this->sections[ $sectionId ] = new Section( $this, $sectionId );
    }
    return $this->sections[ $sectionId ];
  }

  public function FindSectionByType( string $type, int $n = 1 )
  { $this->Sections();
    foreach ($this->sections as $section)
    { if ($section->type == $type && --$n <= 0) return $section;
    }
    if ($type != 'waiting' && $type != 'beavers' && $type != 'cubs' && $type != 'scouts' && $type != 'adults')
      throw new \Exception( "Unknown section type: $type" );
    return null;
  }
  
	/** Fetch all terms for all accessible sections so they are available for other methods.
   * This method should only be called from class Section.
	 * 
	 * @return void
	 */
  public function InitialiseTerms()
  { $this->Sections();
    $apiTerms = $this->PostAPI( 'api.php?action=getTerms' );
    //var_dump( $apiTerms );
    foreach ($this->sections as $section)
    { if (isset($apiTerms->{$section->id})) $section->InitialiseTerms( $apiTerms->{$section->id} );
      else $section->InitialiseTerms( array() );
    }
  }
  
	/** Check whether we have logged in.
   *
   * Note that this returns a login if we have (during this session, which may cover several requests)
   * presented credentials (an email and password) and have received in return an authorisation
   * token (userid and secret).  There is no guarantee that the token is still valid (although they
   * seem to have long lifetimes), nor that it has permission to access anything in particular
   * (which is determined by permissions given by the user to the application in the External Access
   * part of Settings/My Account Details).
	 * 
	 * @return string|null  the email address used to log in.
	 */
	public function IsLoggedIn() {
		return $this->email;
	}
  
  /** Check whether we are logged in as a leader */
  public function IsLoggedInAsLeader() {
    if (!$this->IsLoggedIn()) return false;
    return count($this->Sections()) > 0;
  }
	
	/** 
	 * Authorize the API with the username and password provided
	 * 
	 * @param string $email    Email address of user to authorize
	 * @param string $password Password of the user to authorize
	 * 
	 * @return boolean;
	 */
	public function Login( $email, $password )
  { $apiData = $this->PostAPI( 'users.php?action=authorise',
                                  ['password'=>$password, 'email'=>$email] );
		if (!isset($apiData->secret)) {
			return false;
		}
    $this->secret = $apiData->secret;
		$this->userId = $apiData->userid;
    $this->email = $email;
    $_SESSION['OSM_USERID'] = $this->userId;
		$_SESSION['OSM_SECRET'] = $this->secret;
    $_SESSION['OSM_EMAIL'] = $this->email;
		return true;
	}
  
	/** Logout of OSM
	 *
	 * On exit, no further calls may be made through the object until after a successful Login.
	 * 
	 * @return void;
	 */
	public function Logout( ) {
    $apiData = $this->PostAPI( 'ext/users/auth/?action=logout' );
		$this->secret = $this->userId = $this->email = null;
    unset( $_SESSION['OSM_EMAIL'] );
    unset( $_SESSION['OSM_USERID'] );
		unset( $_SESSION['OSM_SECRET'] );
	}
  
	/** Make an API call to fetch information.
   *
   * Although this method is public, it should only be used from within OSM and related classes.
	 * 
	 * @param string   $url       The URL to query, relative to the base URL
	 * @param string[] $parts     The URL parts, encoded as an associative array
	 * @param boolean  $returnErrors true iff all errors should result in an exception; otherwise
   *                            certain API errors (e.g. permissions, invalid arguments etc) will
   *                            be indicated by setting the OSM errorCode and errorMessage
   *                            properties and returning a null value.
	 * 
	 * @return string[];
	 */
	public function PostAPI($url, $postArgs=array(), $throwErrors = true ) {
    $this->errorCode = $this->errorMessage = '';
		if ($this->curlHandle === null) $this->curlHandle = curl_init();
  
    // Include API Id and token as POST fields
		$postArgs['apiid'] = $this->apiId;
    $postArgs['token'] = $this->token;
		
    // Include UserId and Secret if they are known (as a result of an earlier authorise request)
		if ($this->userId)
    { $postArgs['userid'] = $this->userId;
		  $postArgs['secret'] = $this->secret;
    }
		
		$data = http_build_query( $postArgs );
    
    curl_setopt( $this->curlHandle, CURLOPT_URL, $this->base . $url );
		curl_setopt( $this->curlHandle, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $this->curlHandle, CURLOPT_POST, 1 );
		curl_setopt( $this->curlHandle, CURLOPT_CONNECTTIMEOUT, 2 );
		curl_setopt( $this->curlHandle, CURLOPT_RETURNTRANSFER, true );
    if ( !isset( $this->apiTimes[$url] ) )
      $this->apiTimes[$url] = (object)['count'=>0, 'nano'=>0];
    $this->apiTimes[$url]->nano -= \hrtime( true );
		$msg = curl_exec( $this->curlHandle );
    $this->apiTimes[$url]->nano += \hrtime( true );
    $this->apiTimes[$url]->count += 1;
    
    if($msg === false){
      echo "Curl returned an error<br/>\n";
      throw new \Exception( curl_error($this->curlHandle) );
    }
    //echo htmlspecialchars( "JSON for {$this->base}$url is \"$msg\"" ), "<br/><br/>\n";
		$out = json_decode($msg);
    // echo "Out for ", htmlspecialchars($url), " is "; var_dump( $out );

    if (is_object( $out )) {
      if (isset( $out->error )) {
        //echo htmlspecialchars( $this->base . $url ), "<br/>\n";
        //var_dump( $out );
        if (is_string( $out->error )) {
          $this->errorCode = 'Unset';
          $this->errorMessage = $out->error;
        } else {
          $this->errorCode = $out->error->code;
          $this->errorMessage = $out->error->message;
        }
        return null;
      }
      return $out;
    } else if ($out === false || $out === null)
      // Logged in user doesn't have permission to use this API so indicate this by returning null
      return null;
    else if (is_array( $out )) return $out;
    throw new Exception( "OSM returned unexpected content" );
	}

	/** Make an API call to fetch information.
   *
   * Although this method is public, it should only be used from within OSM and related classes.
	 * 
	 * @param string   $url       The URL to query, relative to the base URL
	 * @param string[] $etArgs    The URL parts, encoded as an associative array
	 * 
	 * @return string[];
	 */
	public function GetAPI($url, $getArgs=array() )
  { if ($getArgs) $url .= '?' . http_build_query( $getArgs );
    
		echo htmlspecialchars( "Query: {$this->base}$url" ), "<br/>\n";
		if ($this->curlHandle === null) $this->curlHandle = curl_init();
    curl_setopt( $this->curlHandle, CURLOPT_CAINFO, __DIR__ . '/cacert.pem' );
		curl_setopt( $this->curlHandle, CURLOPT_URL, $this->base . $url );
		curl_setopt( $this->curlHandle, CURLOPT_HTTPGET, true );
		curl_setopt( $this->curlHandle, CURLOPT_CONNECTTIMEOUT, 2 );
		curl_setopt( $this->curlHandle, CURLOPT_RETURNTRANSFER, true );
		$msg = curl_exec( $this->curlHandle );
    if($msg === false){
      print "error: " . curl_error($this->curlHandle) . "<br/>\n";
    }
    echo "JSON is \"", htmlspecialchars( $msg ), "\"<br/><br/>\n";
		$out = json_decode($msg);
		return $out;
	}

  /** Return array of members who are children of the logged-in user
   *
   * @return Scout[]
   */   
  public function MyChildren() {
    if ($this->myChildren == null) {
      $this->myChildren = array();
      $t = $this->PostAPI( "ext/mymember/dashboard/?action=getNextThings" );
      if ($t && $t->data && $t->data->widget_data) {
        foreach( $t->data->widget_data as $sectionId => $children ) {
          $section = $this->FindSection( $sectionId, true );
          foreach ($children as $scoutId => $widgets) {
            $this->myChildren[$scoutId] = $section->FindScout( $scoutId, true );
            $this->myChildren[$scoutId]->isMyChild = true;
          }
        }
      }
    }
    return $this->myChildren;
  }
  
  public function PrintAPIUsage() {
    foreach ($this->apiTimes as $url => $a) {
      echo number_format( $a->nano / 1000000000, 3 ),
                                " seconds for {$a->count} calls of $url<br/>\n";
    }
  }

  /** Return array of sections available to the logged-in user
   *
   * @return Section[]
  */
  public function Sections() {
    if ($this->leaderSections === null) {
      $this->currentSection = null;
      $this->leaderSections = array();
      // The following call returns an array of sections to which the current user has access.
      // The API call returns an array of objects, one for each section to which the logged-in user
      // has access.  The contents of each object are described in method
      // Section->ApiUseGetUserRoles
      $apiSections = $this->PostAPI( 'api.php?action=getUserRoles' );
      //var_dump( $apiSections );
      if (is_array( $apiSections )) {
        foreach ($apiSections as $apiSection) {
          $section = $this->FindSection( intval( $apiSection->sectionid ), true );
          $section->ApiUseGetUserRoles( $apiSection );
          $this->leaderSections[ $section->id ] = $section;
          if ($this->currentSection == null || $apiSection->isDefault)
            $this->currentSection = $section;
        }
      }
    }
    return $this->leaderSections;
  }

	/**
	 * Get a list of patrols
	 * 
	 * @param string $sectionid The section ID returned by getTerms()
	 * 
	 * @return Object
	 */
	public function getPatrols($sectionid) {
		$patrols = $this->PostAPI('users.php?action=getPatrols&sectionid='. $sectionid);
    return $patrols->patrols;
	}
	
	/**
	 * List of records of the kids present in term $termid
	 * 
	 * @param string $sectionid The section ID returned by getTerms()
	 * @param string $termid    The term ID returned by getTerms()
	 * 
	 * @return Object
	 */
	public function getKidsByTermID($sectionid, $termid) {
		return $this->PostAPI('challenges.php?termid='.$termid.'&type=challenge&section=scouts&c=community&sectionid='. $sectionid, array());
	}
}

class Badge extends BaseObject
{ /** @var string  badge identifier.  Not unique as a badge may exist in several versions. */
  private $id;

  /** @var string  unique identifier for the badge, consisting of its id and version. */
  public $idv;
  
  /** @var string  Name of sub-group within the type.  E.g. 'Pre 2018' */
  private $group;

  /** @var string  the name of the badge */
  private $name;
  
  /** @var integer  key for sorting badges */
  private $order;

  /** @var Requirement[]  array of requirements for this badge, indexed by field Id */
  private $requirements;
  
  /** @var integer  Type of badge: numeric codes for CHALLENGE, ACTIVITY, STAGED or CORE */
  private $type;
  const CHALLENGE = 1;
  const ACTIVITY = 2;
  const STAGED = 3;
  const CORE = 4;
  
  static public $rules = ['beavers'=>['Outdoors'=>['a'=>2],
                                      'Skills'=>['b'=>3,'c'=>3],
                                      'World'=>['d'=>4]
                                     ],
                          'cubs'=>['Outdoors'=>['b'=>2],
                                   'Skills'=>['d'=>4]
                                  ],
                          'scouts'=>['Skills'=>['b'=>5],
                                     'Outdoors'=>['b'=>4]
                                    ]
                         ];
  
  /** @var string  version of the badge. */
  private $version;
  
  public function __construct( $idv ) {
    $this->idv = $idv;
  }

  public function __get( $property ) {
    switch ($property) {
      case 'group':
      case 'id':
      case 'name':
      case 'requirements':
      case 'type':
      case 'version':
        assert( $this->$property !== null );
        return $this->$property;
      default:
        throw new exception( "Badge->$property not found" );
    }
  }
  
  public function __toString() {
    $s = $this->name;
    if ($this->group) $s .= " ({$this->group})";
    return $s;
  }

  public function ApiUseGetBadgeStructure( $apiData, $apiTasks ) {
    
    $this->id = $apiData->badge_id;
    $this->version = $apiData->badge_version;
    assert( $this->idv === $apiData->badge_identifier );
    $this->name = $apiData->name;
    $this->group = $apiData->group_name;
    $this->type = $apiData->type_id;
    $this->requirements = array();
    foreach ($apiTasks[1]->rows as $field) {
      $this->requirements[$field->field] = new Requirement( $this, $field );
    }
  }  
}

/** The work done towards a badge by a scout */
class BadgeWork {
  /** @var bool  True iff the badge has been awarded. */
  public $awarded;

  /** @var Badge  the badge for which this object records progress. */
  public  $badge;
  
  /** @var bool  True iff the badge has been completed. */
  public $completed;
  
  /** @var string[]  array of progress notes, indexed by the badge's requirement ids. */
  public $progress;
  
  /** @var Scout  the scout who has done this badge work. */
  public $scout;
  
  public function __construct( Scout $scout, Badge $badge, \stdClass $apiItem ) {
    assert( $scout->id == $apiItem->scoutid );
    $this->scout = $scout;
    $this->badge = $badge;
    $this->completed = $apiItem->completed != 0;
    $this->awarded = $apiItem->awarded != 0;
    $this->progress = array();
    foreach ($badge->requirements as $id => $requirement) {
      $propertyName = '_' . $id;
      if (property_exists( $apiItem, $propertyName ))
        $this->progress[$id] = $apiItem->$propertyName;
      else $this->progress[$id] = null;
    }
  }
  
  public function Text( Requirement $requirement ) {
    if (!isset( $this->progress[$requirement->id] )) return '';
    return $this->progress[$requirement->id];
  }


  public function HasMet( Requirement $requirement ) {
    if ($this->completed || $this->awarded) return true;
    if (!isset( $this->progress[$requirement->id] )) return false;
    if (substr( $this->progress[$requirement->id], 0, 1 ) == 'x') return false;
    return true;
  }
  
  /** Returns whether the given requirement can be omitted because enough other requirements in the
   * same group have been satisfied.
   */
  public function CanSkip( Requirement $requirement ) {
    $sectionType = $this->scout->section->type;
    // If we don't have a special set of rules for this section, every requirement must be met.
    if (!isset( Badge::$rules[$sectionType] )) return false;
    $rules = Badge::$rules[$sectionType];
    // If we don't have a special rule for this badge, every requirement must be met.
    if (!isset($rules[$this->badge->name])) return false;
    $rules = $rules[$this->badge->name];
    // If we don't have a special rule for this requirement's group, every requirement in the group
    // must be met.
    if (!isset( $rules[$requirement->group] )) return false;
    // Otherwise, count the requirements already met within this requirements group, and if there
    // are as many as required by the special rule, we don't need to meet this requirement.
    $count = 0;
    foreach ($this->badge->requirements as $r) {
      if ($r->group == $requirement->group && $this->HasMet( $r )) $count++;
    }
    return $count >= $rules[$requirement->group];
  }
  
  /** Returns whether further work on the given requirement needs to be done by this scout.  This is
   * the case unless the scout has completed the requirement, has completed other requirements
   * which complete the group of requirements, or has completed or been awarded the whole badge.
   
  public function StillNeeds( Requirement $requirement ) {
    if ($this->HasMet( $requirement )) return false;
    $sectionType = $this->scout->section->type;
    // If we don't have a special set of rules for this section, every requirement must be met.
    if (!isset( Badge::$rules[$sectionType] )) return true;
    $rules = Badge::$rules[$sectionType];
    // If we don't have a special rule for this badge, every requirement must be met.
    if (!isset($rules[$this->badge->name])) return true;
    // If we don't have a special rule for this requirement's group, every requirement in the group
    // must be met.
    $rules = $rules[$this->badge->name];
    if (!isset( $rules[$requirement->group] )) return true;
    // Otherwise, count the requirements already met within this requirements group, and if there as
    // as many as required by the special rule, we don't need to meet this requirement.
    $rule = $rules[$requirement->group];
    $count = 0;
    foreach ($this->badge->requirements as $r) {
      if ($r->group == $requirement->group && $this->HasMet( $r )) $count++;
    }
    return $count < $rules[$requirement->group];
  } */
}

class Event extends BaseObject
{ /** @var Section the section containing this event */
  public $section;
  
  /** @var OSM the OSM object used to access this event */
  public $osm;
  
  /** @var int a globally unique identifier for this event */
  public $id;
  
  /** @var string[] an array of attendance strings, indexed by member Id.  Possible element values
   *            are "", "Invited", "No", "Reserved", "Show in Parent Portal" or "Yes". */
  private $attendance;
  
  /** @var Scout[] an array of members who may attend this event.  This contains the members to
   *            which you can send invitations in OSM's web interface. */
  private $attendees = null;
  
  /** @var float the cost of the event, or null if the cost is not yet determined. */
  public $cost;
  
  /** @var Event[]|null  array of events linked to this one by dint of being shared copies of it.
   *            A null value indicated we haven't yet enquired whether any such events exist. */
  private $linkedEvents = null;
  
  /** @var string the name of the event. */
  public $name;
  
  /** @var Date|null  the date of the event, or of the first day of the event for a multi-day event.
   */
  public $startDate;
  
  /** @var string[]|null  an array translating user-facing column names to internal column names. */
  private $userColumns = null;
  
  /** @var Date|null  the last day of the event.  If the time component is not known it will be set
   *           to 23:59:59.
   */
   public $endDate;
  
  /** Constructor for an OSM Event
   * 
   * Although this is a public constructor, it should be called only from the Events method of class
   * Section.
   *
   * @param Section $section the section to which this event belongs
   * @param $apiEvent an object with properties describing the OSM event to be created.  This object
   *           will typically have been created by decoding the JSON response of an OSM API call and
   *           will have the following properties.
   *           - eventid: a string of digits giving the globally unique identifier of this event.
   *           - name: the name of the event
   *           - type: null in all the examples seen
   *           - startdate: the start date of the event in the format "yyyy-mm-dd"
   *           - enddate: the end date of the event in the format "yyyy-mm-dd".  May be null foreach
   *             single day events.
   *           - starttime: the start time of the event, or null if not given.
   *           - endtime: the end time of the event, or null if not given.
   *           - cost: the cost in pounds and pence of event.  A value of "-1.00" indicates the cost
   *             will be announced later.
  */
  public function __construct( Section $section, \stdClass $apiEvent )
  { $this->section = $section;
    $this->osm = $section->osm;
    $this->id = $apiEvent->eventid;
    $this->cost = $apiEvent->cost == "-1.00" ? null : floatval( $apiEvent->cost );
    $this->name = $apiEvent->name;
    $this->startDate = date_create( $apiEvent->startdate );
    $this->endDate = $apiEvent->enddate ? date_create( $apiEvent->enddate . " +1 day -1 second" ) :
                                                                                               null;
  }
  
  public function __toString()
  { if ($this->startDate) return $this->name . " on " . $this->startDate->format( 'j-M-Y' );
    return $this->name;
  }
  
  /** Fetch list of attendees for this event.
   *
   * @return EventAttendance[]
   */
  public function Attendees() {
    if ($this->attendees === null) {
      $this->attendees = array();
      foreach ($this->LinkedEvents() as $event) {
        $term = $event->section->TermAt( $event->startDate );
        $term = $term ? $term->id : 0;
        $apiObject = $this->osm->PostAPI( "ext/events/event/?action=getAttendance" .
                                          "&eventid={$event->id}&sectionid={$event->section->id}" .
                                          "&termid=$term" );
        foreach ($apiObject->items as $item) {
          $this->attendees[] = new EventAttendance( $event, $item );
        }
      }
    }
    return $this->attendees;
  }
  
  /** How has this event been shared?
   * @return \Event[]  array of events which form part of this event.  The array will always
   *           contain this event, but for a shared event it will also contain all those events
   *           which are shared copies of this event which have been accepted by their sections.
   */
  public function LinkedEvents() {
    if ($this->linkedEvents === null) {
      $this->linkedEvents = array( $this );
      $apiLinks = $this->osm->PostAPI( "ext/events/event/sharing/?action=getStatus" .
                                            "&eventid={$this->id}&sectionid={$this->section->id}" );
      foreach ($apiLinks->items as $item) {
        if ($item->groupname == 'TOTAL' || $item->status != 'Accepted') continue;
        if ($section = $this->osm->FindSection( $item->sectionid )) {
          if ($event = $section->FindEvent( $item->eventid ) ) $this->linkedEvents[] = $event;
        }
      }
    }
    return $this->linkedEvents;
  }
  
  public function PopulateDetails() {
    if ($this->userColumns !== null) return;
    // The object returned by the following call has the properties below:
    // eventid   numeric string giving the event Id, which we already know.
    // name      string giving the name of the event.
    // type      semantics are unknown
    // startdate string of form 'YYYY-MM-DD' giving the start date of the event.
    // enddate   string of form 'YYYY-MM-DD' giving the end date of the event.
    // starttime string of form 'HH:MM:SS' giving the start time of the event (24 hour clock).
    // endtime   string of form 'HH:MM:SS' giving the start time of the event (24 hour clock).
    // cost      numeric string with two decimals giving the fee for the event
    // location  string giving the location of the event
    // notes     string giving private notes not made available through the parents' portal.
    // notepad   string for event planning
    // publicnotes string giving notes shown on the parents' portal.
    // config    string defining a JSON array of objects which describe the user-defined columns added
    //           to the list of attendees.  Each object has the following four string properties:
    //           id    gives the internal name of a user-defined field in the list of attendees.
    //                 This will always be of the form 'f_1', f_2', 'f_3' etc.
    //           name  name the user sees for the user-defined field.
    //           pL    If set, show this field in the parent portal; if set to anything other than
    //                 the name, will be used as a description in the parent portal.
    //           pR    1 if this is compulsory in the parent portal; otherwise empty.
    // extra     a string.  For shared events (either original or copies) this defines a JSON object
    //           with properties related to the sharing (details unknown).  For unshared events this
    //           has been seen to be the empty string.
    // sectionid numeric string giving the id of the section containing this event.
    // googlecalendar     semantics are unknown
    // archived  boolean string.  1 iff this event has been archived
    // soft_deleted boolean string.  1 iff this event has been deleted
    // confdate  string of form 'YYYY-MM-DD' giving the confirmation deadline from Parent Portal
    //           Configuration.
    // allowchanges boolean string (0 or 1)  1 implies changes are allowed until the confirmation
    //           deadline.
    // disablereminders boolean string (0 or 1)  1 implies send invitation just once; 0 implies send
    //           reminders until parents reply.
    // attendancelimit numeric string giving maximum number of people who can accept the event, or
    //           zero to indicate no such limit.
    // limitincludesleaders boolean string (0 or 1)' => string '0' (length=1)
    // allowbooking boolelan string (0 or 1)  0 implies that parents will not be able to sign up for
    //           ' => string '1' (length=1)
    // attendancereminder numeric string giving the number of days before the event that reminders
    //            will be sent, or 0 for no reminders.
    // approval_status  semantics are unknown
    // _shared_event_confdate_lock_enabled  semantics uncertain
    // _shared_event_confdate_locked  semantics uncertain
    // _shared_event_details_locked  semantics uncertain
    // _shared_event_myscout_details_locked  semantics uncertain
    // properties  semantics uncertain
    // badgelinks  semantics uncertain, probably related to badges available from event
    // equipment    semantics uncertain, probably relates to equipment required for the event.
    // _past_event_read_only  boolean, semantics uncertain
    // _attendance_read_only  boolean, semantics uncertain
    // has_myscout  boolean, semantics uncertain
    // structure  array of objects describing the standard columns of the attendees view.
    $apiData = $this->osm->PostAPI( "ext/events/event/?action=getStructureForEvent" .
                                            "&sectionid={$this->section->id}&eventid={$this->id}" );
    $this->userColumns = array();
    if ($apiData->config)
    {
      foreach (json_decode($apiData->config) as $item) {
        $this->userColumns[$item->name] = $item->id;
      }
    }
  }
  
  /** Return the internal name equivalent to the given user-visible column name for an attendance at
   * this event.
   */
  public function UserColumn( string $username ) {
    $this->PopulateDetails();
    if (array_key_exists( $username, $this->userColumns )) return $this->userColumns[$username];
    return null;
  }
}

class EventAttendance
{ /** @var Event  the event attended.  Note that where an event A has been shared as event B in
   *           another section, attendance at event B will be included in the attendance list for
   *           event A but will still be linked through this property to event B.
   */
  public $event;
  
  /** @var Scout  the member attending. */
  public $scout;
  
  /** @var string[]  array of user-defined column contents.  The keys are the internal column names
   *           e.g. f_1, f_2, etc.  The values are the column values for this attendance.  The
   *           translation from user-facing column names to internal column names is given by the
   *           event's UserColumn() method.
   */
  private $columns = array();
  
  /** Construct an description of an attendance, including user-defined columns if present, from an
   * object returned by the API call ext/events/event/?action=getAttendance.
   *
   * @param Event $event the event attended.  This attendance may be listed under another event
   *           if that event has been shared.
   * @param \stdClass $apiObject the object returned by the API call
   *           ext/events/event/?action=getAttendance to describe this attendance.
   */
  public function  __construct( Event $event, $apiObject )
  { $osm = $event->osm;
    $this->scout = $event->section->FindScout( $apiObject->scoutid );
    // Populate scout's fields from information returned by the getAttendance API call.
    $this->scout->ApiUseGetAttendance( $apiObject );
    $this->event = $event;
    $this->status = $apiObject->attending;
    foreach (preg_grep( "/^f_\d+\$/", array_keys( get_object_vars($apiObject) )) as $k) {
      $this->columns[$k] = $apiObject->{$k};
    }
  }
  
  /** Returns the value of a user-defined column for this attendance.
   *
   * @param string $name  the name by which the user knows the column.  E.g. "On coach" or
   *           "Discount".
   *
   * @returns mixed  the value of the column for this attendance.  Returns null if the column is not
   *           defined.
   */
  public function UserColumn( $name ) {
    $fname = $this->event->UserColumn( $name );
    if ($fname && array_key_exists( $fname, $this->columns )) return $this->columns[$fname];
    return null;
  }
}  

class Requirement {
  public $id;
  public $badge;
  public $name;
  public $description;
  public $group;
  
  public function __construct( Badge $badge, \stdClass $apiField ) {
    $this->id = $apiField->field;
    $this->badge = $badge;
    $this->name = $apiField->name;
    $this->description = $apiField->tooltip;
    $this->group = $apiField->module;
  }
  
  public function __toString() {
    return $this->name . ' for ' . $this->badge->name . ' badge';
  }
}

/** Object representing a scout (or leader) in a section.
 *
 * As far as possible OSM uses the same scoutid for the same human person in whatever section he or
 * she appears.  This object does not, however, model the person as such but only the person as they
 * appear in a particular section.  This design decision reflects the fact that, as far as we can
 * see, the API does not allow information to be discovered about a scout independant of a section.
 *
 * Scouts are initially created with almost no content and properties are set from the results of
 * various API calls.
 */
class Scout extends BaseObject
{ /** Has ApiGetIndividual been called to fill in those fields it can?  If the call fails for a
   * particular member this will still be set to true so that we avoid making multiple calls to get
   * data we are not authorised to see. */
  private $apiGotIndividual = false;

  /** @var BadgeWork[] array of objects (indexed by badge id & version) showing the progress
   *            made by this scout towards various badges.  This array will be populated as required
   *            during calls to method BadgeWork.
   */
  private $badgeWork = array();

  private $customData = null;
  
  /** @var Date  Date of birth.  Accessible via __get method which will query the API to populate
   *  this and other properties if access is attempted. */
  private $dob = null;
  
  private $dateStartedSection;
  
  private $firstName = null;
  
  public $id;

  /* @var bool true iff this scout is one of the logged-in users children accessible through
   * 'My Children' in the standard OSM interface. */
  public $isMyChild = false;

  private $lastName = null;
  
  /** @var OSM the OSM object used to access this event */
  private $osm;
  
  /** @var integer  the id of the patrol this member is in, or null to indicate we have not queried
   * the API for this information.  Some special patrol ids are defined:
   *            -2: leaders patrol
   */
  private $patrolId = null;
  
  /** @var integer  the section in which this scout is a member.  If the same person is a member in
   * two sections (either simultaneously or sequentially) they should be represented by two Scout
   * objects having the same scoutId but different sections.
   * Of course, if the person has been set up independently in the two sections, rather than being
   * shared between them, the scoutIds will also differ.
   */
  public $section;

  /** Construction function for Scout
   *
   * @param Section $osm  the section to which this scout is attached.
   * @param int $scoutId  the unique id of the scout.  Note that although this id is unique to the
   *           scout it may be shared by several Scout objects representing the same scout in
   *           different sections.
   *
   * @return Scout Note that the scout as returned has almost no properties set.  If further
   *           information about the scout is available it should be added by calling one of the
   *           Api... methods depending upon which API was used to discover the scout.
   */
  public function __construct( Section $section, int $scoutId ) {
    $this->section = $section;
    $this->osm = $section->osm;
    $this->id = $scoutId;
  }

  public function __get( $property ) {
    switch ($property) {
      case 'dateStartedSection':
      case 'dob':
      case 'firstName':
      case 'lastName':
        if ($this->$property === null) $this->ApiGetIndividual();
        return $this->$property;
      case 'patrolId':
        return $this->getPatrolId();
      case 'customData':
       if ($this->customData === null) $this->ApiCustomData();
       return $this->customData;
      default:
        throw new \Exception( "Scout->$property not found" );
    }
  }
  
  public function __toString()
  { if ($this->firstName === null) return "Scout {$this->id}";
    return $this->Name();
  }

  /** Fetches custom data about the member.  This includes the member's contact details, together
   * with their primary, secondary and emergency contacts etc.
   */
  private function ApiCustomData()
  { if ($this->customData !== null) return;
    $this->customData = new \stdClass();
    if ($this->section->Permissions( 'member' ) > 0)
      $apiData = $this->osm->PostAPI( "ext/customdata/?action=getData&section_id={$this->section->id}",
                              array( 'associated_id'=>$this->id, 'associated_type'=>'member',
                                     'context'=>'members', 'group_order'=>'section' ), false );
    elseif ($this->isMyChild) {
      $apiData = $this->osm->PostAPI( "ext/customdata/?action=getData&section_id={$this->section->id}",
                              array( 'associated_id'=>$this->id, 'associated_type'=>'member',
                                     'context'=>'mymember', 'group_order'=>'section' ), false );
    }                              
    if ($apiData && $apiData->data) {
      $apiData = $apiData->data;
      // var_dump( $apiData );
      foreach ($apiData as $apiGroup)
      { $group = new \stdClass();
        $group->name = $apiGroup->name;
        foreach ($apiGroup->columns as $apiColumn) {
          if ($apiColumn->type == 'checkbox')
            $group->{$apiColumn->varname} = $apiColumn->value == 'yes' ? true : false;
          else switch ($apiColumn->varname) {
            case 'firstname':
            case 'lastname':
              $group->{$apiColumn->varname} = trim( $apiColumn->value );
              break;
            default:
              $group->{$apiColumn->varname} = $apiColumn->value;
          }
        }
        $this->customData->{$apiGroup->identifier} = $group;
      }
    }
  }

  /** Populate properties of the scout supplied by API call GetAttendance.
   *
   * @param object $apiObject  an object returned by the API containing basic information about the
   *           member.  We take a particular interest in its following properties:
   *           scoutid string giving unique id for this member.  Provided the member has been moved
   *                     or shared between sections (rather than have data re-entered) this id will
   *                     be the same in all sections.
   *           firstname string
   *           lastname string
   *           patrolid  numeric string identifying the scout's patrol.  The leaders patrol always
   *                     has id -2.
   * @param int $section the section in which this scout is a member.
   */
  public function ApiUseGetAttendance( \stdClass $apiObject ) {
    // echo "<h2>Scout {$this->id} -&gt;ApiUseGetAttendance</h2>\n"; var_dump( $apiObject );
    assert( $this->id == $apiObject->scoutid );
    $this->firstName = $apiObject->firstname;
    $this->lastName = $apiObject->lastname;
    $this->patrolId = $apiObject->patrolid;
  }
  
  public function ApiUseGetBadgeRecords( Badge $badge, \stdClass $apiItem ) {
    $this->SetName( $apiItem->firstname, $apiItem->lastname );
    $this->badgeWork[$badge->idv] = new BadgeWork( $this, $badge, $apiItem );
    // echo "Scout $this, work for badge $badge ({$badge->idv})<br/>\n"; var_dump( $this->badgeWork[$badge->idv] );
  }
  
  /* Make a call to API post ext/members/contact/?action=getIndividual and use the result to
   * populate properties of the scout.
   *
   * @param Section $section  the section the scout is in.  If this parameter is not given then
   *           OSM's current default section will be assumed.
   *
   * scoutid  will match existing
   * firstname should match existing
   * lastname should match existing
   * photo_guid  used somehow to construct the URL of a photo for this scout.  Not used at present.
   * email1, 2, 3 & 4  appear to be always the empty string.
   * phone1, 2, 3 & 4  appear to be always the empty string.
   * address & address2  appear to be always the empty string
   * dob       date of birth in form 'YYYY-MM-YY'.  Not used at present.
   * started   date joined scouting movement, in form 'YYYY-MM-DD'.  Not used at present.
   * joining_in_yrs seems to be zero even for people who have been a member for several years (and
   *           have the joining in badges showing in OSM).
   * parents, notes, medical, religion, school, ethnicity, subs, custom1, custom2, custom3, custom4,
   *           custom5, custom6, custom7, custom8, custom9  all appear to be always the empty string.
   * created_date  date and time this record was created, in the form 'YYYY-MM-DD HH:MI:SS'.
   * last_accessed date and time this record was last access, in the form 'YYYY-MM-DD HH:MI:SS'.  It
   *           is not quite clear what constitutes 'access'.  Not used at present.
   * patrolid  the id of the patrol in which this scout is a member.  Patrols are specific to a
   *           section, except id '-2' which is the leaders patrol in all sections.
   * patrolleader small integer indicating role in patrol: 0=>member, 1=>second; 2=>sixer.  Not used
   *           at present.
   * startedsection   date joined this section, in form 'YYYY-MM-DD'.
   * enddate   date left this section, in form 'YYYY-MM-DD', or null if this isn't yet known.
   * age       narrative string such as '10 years and 5 months'.  This is the age at the time of the
   *           query, not at the time of any event or term.
   * age_simple  as age, but in shorter form such as '10 / 05'.
   * sectionid the id of the section to which this record relates.  Should be the same as the id of
   *           the related section object.
   * active    meaning not quite certain.  A value of true may indicate the scout is still (at the
   *           time of enquiry) a member is this section, or perhaps in any section.
   * meetings  a number which may be a count of total meetings attended.  This property is absent if
   *           the enquiry did not include a term.
   */

  public function ApiGetIndividual() {
    if (!$this->apiGotIndividual) {
      $this->apiGotIndividual = true;
      $apiData = $this->osm->PostAPI( "ext/members/contact/?action=getIndividual&context=members" .
                                    "&sectionid={$this->section->id}&scoutid={$this->id}&termid=0" );
      if ($apiData->ok) {
        $apiData = $apiData->data;
        assert( $this->id == $apiData->scoutid );
        $this->dob = date_create( $apiData->dob );
        $this->firstName = $apiData->firstname;
        $this->lastName = $apiData->lastname;
        $this->patrolId = $apiData->patrolid;
        $this->dateStartedSection = date_create( $apiData->startedsection );
    } }
  }

  /* Populate properties using the information in an element of the items array returned by API post
   * ext/members/contact/?action=getListOfMembers for a section in a particular term.
   *
   * firstname should match existing
   * lastname  should match existing
   * photo_guid  not currently used
   * patrolid  id number of scout's patrol in their section (-2 for leaders).  Should match
   *           existing.
   * name of patrol  e.g. 'Blue 6er'.  Not currently used.
   * sectionid id number of scout's section.  Should match existing.
   * enddate   date scout left this section (but may have moved to another section).
   * age       e.g. '10 / 5'.  Not used, but can be derived from date of birth.
   * patrol_role_level_label  e.g. 'Sixer'.  Not currently used.
   * active    boolean.  Not currently used.
   * scoutid   will match existing
   */
  public function ApiUseGetListOfMembers( \stdClass $apiItem ) {
    $this->firstName = $apiItem->firstname;
    $this->lastName = $apiItem->lastname;
    $this->patrolId = $apiItem->patrolid;
    assert( $this->section->id == $apiItem->sectionid );
    assert( $this->id == $apiItem->scoutid );
  }

  /* What progress is this scout making towards a particular badge?
   *
   * @returns BadgeWork | null  An object showing the the progress this scout is making towards
   *           the specified badge, or null if no such progress is recorded.
   */
  public function BadgeWork( $badge ) {
    if (!array_key_exists( $badge->idv, $this->badgeWork )) {
      $this->section->TermAt()->ApiGetBadgeRecords( $badge );
      if (!array_key_exists( $badge->idv, $this->badgeWork )) $this->badgeWork[$badge->idv] = null;
    }
    return $this->badgeWork[$badge->idv];
  }
  /** Can this scout skip the given requirement because enough other requirements in the same group
   * have been satisfied?
   *
   * @param $requirement  the requirement about which we are enquiring
   *
   * @return true iff more work is needed.  Note that no work is needed if the badge has been
   *           completed or awarded, if the work has been done, or if sufficient other work in the
   *           same group of requirements has been done (this last only for some badges).  False
   *           otherwise.
   */
  public function CanSkip( Requirement $requirement ) {
    $work = $this->BadgeWork( $requirement->badge );
    if ($work) return $work->CanSkip( $requirement );
    return false;
  }
  
  public function ContactData( $contactName, $fieldName )
  { $this->ApiCustomData();
    if (!isset( $this->customData->{$contactName} )) return null;
    if (!isset( $this->customData->{$contactName}->$fieldName)) return null;
    return $this->customData->{$contactName}->$fieldName;
  }
  
  public function ContactEmail( $contact )
  { if (!$contact) return null; 
    if ($contact->email1) return $contact->email1;
    return $contact->email2;
  }
  
  public function ContactName() {
    if ($this->IsAdult()) return $this->Name();
    $contact = $this->PreferredContact();
    if ($contact == null || !$contact->firstname) return "Parent of {$this}";
    if (!$contact->lastname) return $contact->firstname . ' ' . $this->lastName;
    return $contact->firstname . ' ' . $contact->lastname;
  }
  
  /** Returns the date this scout started in it's associated section.
   */
  public function DateStartedSection() {
    $this->PopulateIndividualData();
    return $this->dateStartedSection;
  }

  /** Returns the email address to be used for this member.
   *
    * @returns string  email address to be used for this member.  This will always be one of the
    *           email addresses of the member's preferred contact.  If the preferred contact has not
    *           marked any email address as 'Receive email from leaders' then an address not so
    *           marked will be used.
    */
  public function Email() {
    $this->ApiCustomData();
    $preferred = $this->PreferredContact();
    if ($this->IsAdult() && isset( $this->customData->contact_primary_member )) {
      return $this->ContactEmail( $preferred ) ?:
             $this->ContactEmail( $this->customData->contact_primary_member );
    }
    return $this->ContactEmail( $preferred );
  }
  
  private function getPatrolId() {
    if ($this->patrolId === null) $this->ApiGetIndividual();
    return $this->patrolId;
  }

  /** Is the given email valid for this member?
   *
   * @param string $email  the email we are thinking about using for this member.
   *
   * @return bool  true iff the given email is a case-insensitive match to one of this member's
   *           contact emails.
   */
  public function HasEmail( string $email )
  { $this->ApiCustomData();
    foreach ($this->customData as $k => $contact)
    { if ($k == 'contact_primary_1' || $k == 'contact_primary_2' || $k == 'contact_primary_member')
      { if (strcasecmp( $contact->email1, $email ) == 0) return true;
        if (strcasecmp( $contact->email2, $email ) == 0) return true;
      }
    }
    return false;
  }

  /** Has this scout met a specified badge requirement?
   *
   * @param $requirement  the requirement about which we are enquiring
   *
   * @return true iff the requirement has been met (including the case where the badge has been
   *           awarded without filling in all the details of the individual requirements).  False
   *           otherwise.
   */
  public function HasMet( Requirement $requirement ) {
    $work = $this->BadgeWork( $requirement->badge );
    if ($work) return $work->HasMet( $requirement );
    return false;
  }
  /** Does this scout require more work on this requirement?
   *
   * @param $requirement  the requirement about which we are enquiring
   *
   * @return true iff more work is needed.  Note that no work is needed if the badge has been
   *           completed or awarded, if the work has been done, or if sufficient other work in the
   *           same group of requirements has been done (this last only for some badges).  False
   *           otherwise.
  public function StillNeeds( Requirement $requirement ) {
    $work = $this->BadgeWork( $requirement->badge );
    if ($work) return $work->StillNeeds( $requirement );
    return true;
  } */

  /** The text associated with this scout's work towards this requirement.
   *
   * @param $requirement  the requirement about which we are enquiring.
   *
   * @returns the text recorded for this scout against the given requirement.  Returns an empty
   *           string if nothing has been recorded.
   */
  public function RequirementText( Requirement $requirement ) {
    $work = $this->BadgeWork( $requirement->badge );
    if ($work) return $work->Text( $requirement );
    return '';
  }

  public function IsAdult()
  { //echo "{$this->Name()} is in patrol {$this->getPatrolId()} section type {$this->section->type}<br/>\n";
    if ($this->getPatrolId() == -2) return true;
    if ($this->section->type == 'adults') return true;
    return false;
  }
  
  public function Name()
  { return $this->firstName . ' ' . $this->lastName;
  }

  /** Returns a shortened name sufficient for use in context of a contact.
   *
   * @return string  usually just the member's first name, but also includes the last name if that
   *           differs from the preferred contact's last name.
   */
  public function NameWithoutContactSurname() {
    $contact = $this->PreferredContact();
    if ($contact && $contact->lastname && (stripos( $this->lastName, $contact->lastname ) !== false
                                       || stripos( $contact->lastname, $this->lastName ) !== false))
      return $this->firstName;
    return $this->Name();
  }

  public function PreferredContact()
  { $this->ApiCustomData();
    if ($this->IsAdult()) {
      $contact = $this->customData->contact_primary_1 ?? null;
      if ($this->ContactEmail($contact)) return $contact;
    }
    $contact = $this->customData->contact_primary_1 ?? null;
    if ($this->ContactEmail($contact)) return $contact;
    $contact = $this->customData->contact_primary_2 ?? null;
    if ($this->ContactEmail($contact)) return $contact;
    return $this->customData->contact_primary_1 ?? null;
  }

  /** Set the scout's first and last names.
   *
   * This method cannot be used to change the scout's name, simply to set it after an API call that
   * may be the first reference to the scout.
   */
  public function SetName( $firstName, $lastName ) {
    assert( !$this->firstName || $this->firstName == $firstName );
    assert( !$this->lastName || $this->lastName == $lastName );
    $this->firstName = $firstName;
    $this->lastName = $lastName;
  }
}

class Section extends BaseObject
{ /** @var boolean  true iff we have populated only the most basic properties of the section. */
  private $skeleton;
  
  /** @var int The Id by which this Section is known to the OSM API. */
  public $id;
  
  /** @var $apiPermissions \stdClass|null  An object, like property permissions, but indicating the
   * permission the current application has been granted (by the current user) to this section.  A
   * value of null indicates that we have not yet asked the API what these permissions are. */
  private $apiPermissions = null;
  
  /** @var null|Event[]  Array of events for this section.  This array contains all events,
   *            not just those for a particular term. */
  private $events = null;
  
  /** @var int  unique identifier of the group in which this section lies. */
  private $groupId;
  
  /** @var string The name of the Group of which this section is a part. */
  private $groupName;
  
  /** @var string The name of this section. */
  private $name;
  
  /** @var OSM  the object through which we are accessing OSM. */
  public $osm;

  /** @var Scout[]  array of scouts, indexed by id, in this section.
   * This array is not necessarily complete, and may be added to by calling FindScout with
   * parameter $create set true.
   */
  private $scouts = null;
  
  /** @var null|Term[] An array of terms for this section, indexed by integers 0.. */
  private $terms = null;
  
  /** @var Term|null  the currently selected term for this section.  By default, this will be the
   * most recent term.
   */
  private $term = null;
  
  /** @var $type string The type (waiting, beavers, cubs, scouts or adults) of the section. */
  private $type;

  /* @var \stdClass|null  Object having properties defining the permissions of the logged-in user in
   * various areas of OSM.  Note these permissions may not be valid through the API (cf.
   * $apiPermissions).  A null value indicates we have not interrogated the API to discover the
   * permissions. */
  private $userPermissions = null;
 
  /** Constructor for an OSM Section
   * 
   * This constructor (and 'new Section') should be called only from class OSM.
   *
   * @param $osm    the OSM object used to create this section.
   * @param int $sectionId  the unique identifier of the section
  */
  public function __construct( OSM $osm, int $sectionId ) {
    $this->osm = $osm;
    $this->id = $sectionId;
  }

  public function __get( $property ) {
    switch ($property) {
      case 'groupId':
      case 'groupName':
      case 'name':
      case 'type':
        if ($this->$property === null) $this->osm->Sections();
        return $this->$property;
      default:
        throw new exception( "Scout->$property not found" );
    }
  }
  
  public function __toString()
  { return $this->name;
  }

  /** Populate properties of this section using information from a call to
   * api.php?action=getUserRoles.
   *
   * @param \stdClass $apiObj  one of the objects returned by the API call.  See below for a detailed
   *           description.
   *
   * @return void
   */
  /* The parameter will have the following properties:
     groupname string giving the name of the group containing the section
     groupid   numeric string identifying the group.  This is the same for all sections
               within the same group but no further use within the API is known.
     sectionid numeric string identifying the section.  Appears to be globally unique.
     sectionname string giving name of section, as shown in OSM interface.
     section   string identifying the section age-group.  Values seen are 'adults',
               'beavers', 'cubs', 'scouts' and 'waiting'.
     isDefault string '0' or '1'.  Exactly one of the sections returned will have value
               '1': the section last used in the OSM web interface.
     permissions   an object describing the permission levels for the current user in the section.
               See the description of method Permission for details of the property names and
               permitted values.  An absent property should be treated as zero.
     regdate   string of form YYYY-MM-DD giving the date on which the section was first
               registered in OSM.
     sectionConfig an object (see below) giving information mainly related to the level of
                subscription paid for.
   The sectionConfig object has the following properties:
     subscription_level  integer: 1=>Bronze, 2=>Silver, 3=>Gold
     subscription_expires string of form YYYY-MM-DD giving expiry date of current
                subscription.
     section_type  string apparently identical to the 'section' property of the section.
     sectionType  string apparently identical to the 'section' property of the section.
     parentSectionId  the only value observed is '0'.
     hasUsedBadgeRecords  boolean.
     subscription_active  boolean.  Note this may relate to automatic renewal being active
               rather than to the subscription actually being current.
     subscription_lastExpires string of form YYYY-MM-DD.  Semantics unclear.
     trial     an object, purpose unknown.  This property is not always present.
     portal    an object with five integer properties specifying which parent portal options
               have been purchased.  1=>yes, 0=>no for events, programme, badges,
               (personal) details and emailbolton (for the Email system).  Two further
               properties 'emailAddress' and 'emailAddressCopy' give the from address and
               address for copies of all emails.
     portalExpires an object with five string properties, with names like the integer
               properties of portal, containing the expiry dates of the parent portal
               subscriptions, together with five integer properties, named as the others
               but with an 'A' appended, whose purpose is unknown.
     meeting_day  string giving the three-letter name of the section's usual meeting day.
               E.g. 'Thu'.  This doesn't seem to be editable in the web interface.
     config_subscriptions_checked  string of form YYYY-MM-DD which appears usually to be
               set to today's date.
  */
  public function ApiUseGetUserRoles( \stdClass $apiObj ) {
    assert( $this->id == intval( $apiObj->sectionid ) );
    $this->groupId = $apiObj->groupid;
    $this->groupName = $apiObj->groupname;
    $this->name = $apiObj->sectionname;
    $this->type = $apiObj->section;
    $this->userPermissions = $apiObj->permissions;
  }

  /** Fetch list of Events for this section
   *
   * @return Event[]
   */
  public function Events() {
    if ($this->events === null) {
      $this->events = array();
      $apiEvents = $this->osm->PostAPI( "events.php?action=getEvents&sectionid={$this->id}" );
      // TODO: Consider reporting back somehow if failed to find events due to a permission issue.
      if ($apiEvents) foreach ($apiEvents->items as $apiEvent) {
        $event = new Event( $this, $apiEvent );
        $this->events[$event->id] = $event;
      }
    }
    return $this->events;
  }
  
  public function FindEvent( int $eventId )
  { $this->Events();
    return isset( $this->events[$eventId] ) ? $this->events[$eventId] : null;
  }
    
  
  public function FindEventByDate ( string $date ) {
    $events = $this->Events();
    $dateObj = date_create( $date );
    foreach ($events as $event) {
      if ($event->startDate == null || $dateObj == null) return null;
      if ($event->startDate->format( 'Y-m-d' ) == $dateObj->format( 'Y-m-d' )) return $event;
    }
    return null;
  }
  
  /** Returns the object representing a particular scout in this section.
   *
   * @param int $scoutId  the integer uniquely identifying this scout.
   * @param bool $create  controls what happens when the requested scout cannot be found in the
   *           section cache.  If $create is true then an object for the scout will be created (this
   *           may involve API calls to discover the scout's properties), otherwise a value null
   *           will be returned.
   *
   * @returns Scout|null  object representing the specified scout in this section.  If the same
   *           person is a member in several sections then they will be represented by a different
   *           object in each section, all having the same scoutId.
   *           The value null will be returned only in the case that parameter bool is false and the
   *           scout object has not already been created.  Note that the scout may be present in the
   *           API in this case.
   */
  public function FindScout( int $scoutId, bool $create = true )
  { if (!isset( $this->scouts[$scoutId] )) {
      if (!$create) return null;
      $this->scouts[$scoutId] = new Scout( $this, $scoutId );
    }
    return $this->scouts[$scoutId];
  }
  
  /** Return full name (including Group) of section
   *
   * @return string Full name of section in the form "GroupName: SectionName"
  */
  public function FullName()
  { return $this->groupName . ': ' . $this->name;
  }
  
  /** Initialise the list of terms for this section
   *
   * This method should be called only from the InitialiseTerms method of class OSM.
   * @param \stdClass[] $apiTerms  an array of objects, each having properties copied from the JSON
   *           returned from the API.
  */
  public function InitialiseTerms( array $apiTerms )
  { $this->terms = array();
    foreach ($apiTerms as $apiTerm)
    { $this->terms[] = new Term( $this, $apiTerm );
    }
  }
  
  public function OSM() {
    return $this->osm;
  }

  /** Returns level of permission the logged-in user has for the given section.
   *
   * @param string $area  one or more strings specifying areas of the API we may wish to access.
   *           The permitted strings are as follows: badge (Qualifications), member (Personal
   *           Details), user (Administration), register (not known) and programme (Programme),
   *           events (Events), flexi (Flexi-Records), finance (Finances) and quartermaster
   *           (Quartermaster).
   *
   * @return int the lowest level of permission the current user has granted this application in the
   *           areas specified in the parameters.  I.e. if the user has granted read permission in
   *           one named area and write permission in another then the result will indicate read
   *           permission.  Values are 0 => No permission, 10 => Read-only, 20 => read and write,
   *           100 => Adminstrator.
   */
  public function Permissions( $area ) {
    // If we don't know what external access permissions have been granted to this Application, ask
    // the API and note the result.
    if ($this->apiPermissions === null) {
      $t = $this->osm->PostAPI( "/ext/settings/access/?action=getAPIAccess&sectionid={$this->id}" );
      foreach ($t->apis as $api) {
        if ($api->apiid == $this->osm->apiId) $this->apiPermissions = $api->permissions;
      }
      if ($this->apiPermissions === null) $this->apiPermissions = new \stdClass();
    }
    // And combine the API permissions with those granted to the user
    $p = 100;
    foreach (func_get_args() as $a) {
      if (property_exists( $this->apiPermissions, $a )) {
        if ($this->apiPermissions->$a < $p) $p = $this->apiPermissions->$a;
      }
      else $p = 0;
    }
    return $p;
  }

  /** Return array containing a Scout object for each member of this section.
   *
   * To get an array of the scouts who are members in some term other than the current one, use the
   * term's 'Scouts' method.
   *
   * @returns Scout[] the scouts who are members in the current term.
   */
  public function Scouts() {
    $term  = $this->TermAt();
    if ($term) {
      $scouts = $term->Scouts();
      return $scouts;
    }
    return array();
  }

  public function Terms()
  { if ($this->terms === null) $this->osm->InitialiseTerms();
    if ($this->terms === null) $this->terms = array();
    return $this->terms;
  }
  
  /** Get term (for this section) which covers the given date.
   *
   * @param \DateTime|null $day a date you want to find the term for.
   *
   * @returns OSTTerm|null  a term including the given date, or as close to doing so as possible.
   *           Null is returned only if there are no terms defined for this section.
  */
  public function TermAt( \DateTime $day = null ) {
    $this->Terms();
    $ts = $day ? $day->getTimestamp() : time();
    //if ($day) echo "Target is {$day->format( 'j-M-Y' )}<br/>\n";
    $bestTerm = null;
    foreach ($this->terms as $term) {
      if ($term->startDate->getTimestamp() <= $ts &&
          $term->endDate->getTimestamp() + 86400 > $ts) {
        // $term includes the required date.  Now prefer it if it is shorter than any previously
        // found term.
        if ($bestTerm === null ||
            $term->endDate->getTimestamp() - $term->startDate->getTimestamp() <
                          $bestTerm->endDate->getTimestamp() - $bestTerm->startDate->getTimestamp())
          $bestTerm = $term;
      }
    }
    if ($bestTerm) return $bestTerm;

    // If no term spans the given date, choose the latest that starts before it
    foreach ($this->terms as $term) {
      if ($term->startDate->getTimestamp() <= $ts &&
            ($bestTerm === null ||
             $term->startDate->getTimestamp() > $bestTerm->startDate->getTimestamp()))
        $bestTerm = $term;
    }
    if ($bestTerm) return $bestTerm;

    // If no term starts before the given date, choose the earliest that starts after it
    foreach ($this->terms as $term) {
      if ($term->startDate->getTimestamp() > $ts &&
            ($bestTerm === null ||
             $term->startDate->getTimestamp() < $bestTerm->startDate->getTimestamp()))
        $bestTerm = $term;
    }
    return $bestTerm;
  }

  /** Returns level of permission the logged-in user has for the given section.
   *
   * @param string $area  one or more strings specifying areas of OSM in which we are interested.
   *           The permitted strings are as follows: badge (Qualifications), member (Personal
   *           Details), user (Administration), register (not known) and programme (Programme),
   *           events (Events), flexi (Flexi-Records), finance (Finances) and quartermaster
   *           (Quartermaster).
   *
   * @return int the level of permission the current user has in all the areas specified in
   *           the parameters.  I.e. if the user has read permission in one named area and write
   *           permission in another then the result will indicate read permission.  Values are 0 =>
   *           No permission, 10 => Read-only, 20 => read and write, 100 => Adminstrator.  Note that
   *           these are the permissions the user has to OSM itself; these permissions may not have
   *           been granted for external access by an application.
   */
  public function UserPermissions( $area ) {
    if ($this->userPermissions === null) $this->osm->Sections();
    if ($this->userPermissions === null) $this->userPermissions = new \stdClass();
    $p = 100;
    foreach (func_get_args() as $a) {
      switch ($a) {
        case 'events':
        case 'member':
        case 'user':
        case 'badge':
        case 'register':
        case 'programme':
        case 'flexi':
        case 'finance':
        case 'quartermaster':
          if (property_exists( $this->userPermissions, $a )) {
            if ($this->userPermissions->$a < $p) $p = $this->userPermissions->$a;
          }
          else $p = 0;
          break;
        default:
          throw new Exception( "$a is not a permission name" );
      }
    }
    return $p;
  }
}

class Term extends BaseObject
{ /** @var bool[]  array, indexed by badge id & version, indicating whether the GetBadgeRecords API
   *            call has been made for the relevant badge.  This call will fetch details of progress
   *            on a given badge for all scouts current in the term.
   */
  private $apiGotBadgeRecords = array();
  
  /** Badge[][]  Array with four elements indexed by Badge::CHALLENGE etc.  Each element, if
   * defined, is an array containing the badges of that type available in the term. */
  private $badges = array();

  public $endDate;

  public $id;

  /** @var string Name of term */
  public $name;
  
  /** @var OSM  the OSM object through which we are connecting to the OSM web API. */
  private $osm;
  
  /** @var Scout[]  array of scouts who were members (of this term's section) during this term.
   *           Initially null, set to an array by the first call of method Scouts. */
  private $scouts = null;
  
  /** @var Section the section for which this is a term */
  public $section;

  public $startDate;
  
  /** Object constructor (should be called only from OSM class)
   *
   * @param Section $section the section to which this term belongs
   * @param $apiTerm an object with properties describing the OSM term to be created.  This object
   *           will typically have been created by decoding the JSON response of an OSM API call and
   *           will have the following attributes (attributes described below as numbers are
   *           returned in the JSON as strings):
   *           termid: numeric string giving a globally unique identifier for this term.
   *           sectionid: numeric string identifying the section for which this is a term.
   *           name: the name of the term.
   *           startdate: first day of the term ("yyyy-mm-dd")
   *           enddate: last day of the term ("yyyy-mm-dd")
   *           master_term: seems always to be null
   *           past: is true if we are currently in the term or it is in the past; is false if there
   *                hasn't yet started.
  */
  public function __construct( Section $section, \stdClass $apiTerm )
  { $this->section = $section;
    $this->osm = $section->OSM();
    $this->id = intval( $apiTerm->termid );
    $this->name = $apiTerm->name;
    $this->startDate = date_create( $apiTerm->startdate );
    $this->endDate = date_create( $apiTerm->enddate );
  }
  
  public function __toString()
  { return $this->name;
  }

  /** Get the list of members active in this section during this term
   */
  public function ApiGetListOfMembers()
  { if ($this->scouts === null) {
      $this->scouts = array();
      $r = $this->osm->PostAPI( "ext/members/contact/?action=getListOfMembers&sort=lastname" .
                                 "&sectionid={$this->section->id}&termid={$this->id}" .
                                 "&section={$this->section->type}" );
      // echo "<h2>$this ApiGetListOfMembers</h2>\n"; var_dump( $r );
      if ($r) {
        foreach ($r->items as $apiItem ) {
          $scout = $this->section->FindScout( $apiItem->scoutid );
          $scout->ApiUseGetListOfMembers( $apiItem );
         $this->scouts[$scout->id] = $scout;
        }
      }
    }
  }
  
  /** Return array of current badges in this term's section
   *
   * @param int $type  one of Badge::CHALLENGE, Badge::ACTIVITY, Badge::STAGED or Badge::CORE
   */
  public function Badges( $type ) {
    if (!isset($this->badges[$type])) {
      $this->badges[$type] = array();
      $r = $this->osm->PostAPI( "ext/badges/records/?action=getBadgeStructureByType" .
                                "&section={$this->section->type}&type_id=$type" .
                                "&term_id={$this->id}&section_id={$this->section->id}" );
      //foreach ($r->structure as $struct) var_dump( $struct );
      foreach ($r->details as $idv => $apiItem) {
        $badge = $this->osm->FindBadge( $idv );
        $apiTasks = $r->structure->$idv;
        $badge->ApiUseGetBadgeStructure( $apiItem, $apiTasks );
        $this->badges[$type][$idv] = $badge;
      }
    }
    return $this->badges[$type];
  }


  public function ApiGetBadgeRecords( $badge ) {
    if (isset($this->apiGotBadgeRecords[$badge->idv])) return;
    $this->apiGotBadgeRecords[$badge->idv] = true;
    $r = $this->osm->PostAPI( "ext/badges/records/?action=getBadgeRecords&term_id={$this->id}" .
                   "&section={$this->section->type}&badge_id={$badge->id}" .
                   "&section_id={$this->section->id}&badge_version={$badge->version}&underscores" );
    foreach ($r->items as $item) {
      $scout = $this->section->FindScout( $item->scoutid );
      $scout->ApiUseGetBadgeRecords( $badge, $item );
    }
  }
  
  public function IsMember( int $scoutId )
  { $this->Scouts();
    if (isset($this->scouts[$scoutId])) return $this->scouts[$scoutId];
    return null;
  }

  public function Scouts() {
    $this->ApiGetListOfMembers();
    return $this->scouts;
  }
  
  public function Section()
  { return $this->section;
  }
}
/* The following URLs have been observed to return useful stuff.

Get details of an individual
https://www.onlinescoutmanager.co.uk/ext/members/contact/?action=getIndividual&sectionid=36850&scoutid=1006049&termid=331890&context=members

Get list of patrols in a section
https://www.onlinescoutmanager.co.uk/ext/settings/patrols/?action=get&sectionid=36850

Get additional information (member address, primary contact etc) about a member
POST https://www.onlinescoutmanager.co.uk/ext/customdata/?action=getData&section_id=36850
with associated_id	1006049 - memberid
associated_type	member
context	members
group_order	section

Get shared events associated with this one
https://www.onlinescoutmanager.co.uk/ext/events/event/sharing/?action=getStatus&eventid=537985&sectionid=38356&_v=2
*/
?>