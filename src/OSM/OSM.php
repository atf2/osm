<?php
/** The following collection of classes provide straightforward access to
 * various parts of the Online Scout Manager software hosted at
 * www.onlinescoutmanager.co.uk.
 *
 * Note that the author has no relationship, other than as a customer, with the
 * owner of Online Scout Manager and that this code is provided as-is with no
 * guarantee of accuracy or correctness.
 
 */
declare(strict_types=1);
namespace atf\OSM;
//define( 'TRACEOSM', '/(Summary|getIndividual)/' );

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
   *
   * @param string $name  the (undeclared) property being set.
   * @param mixed $v  the value to which the named property is being set.
   */
  public function __set( $name, $v ) {
    $trace = debug_backtrace();
    $caller = $trace[0];
    echo "Attempt to set ", get_class($this), "->$name in line {$caller['line']} of {$caller['file']}<br/>\n";
    throw new \Exception( "Attempt to set " . get_class($this) . "->$name" );
  }
}

/*
  Many objects in this collection of classes use lazy evaluation, only making a
  call to the API when the value of a property is required.  Each API returns an
  eclectic mix of information and this comment attempts to summarise which call
  returns what.
  
                  api.php?action=getTerms
                  api.php?action=getUserRoles
                  ext/mymember/dashboard/?action=getNextThings
                  users.php?action=authorise
                  
                authorise getNextThings getTerms getUserRoles
  OSM
    secret          Y
    userId          Y
    
  Section
    id                           Y         Y          Y
    apiPermissions
    easyFundRaising                                   Y
    groupId                                           Y
    groupName                                         Y
    hasUsedBadges                                     Y
    isDefault                                         Y
    meetingDay                                        Y
    name                                              Y
    portalBadges                                      Y
    portalBadgesExpires                               Y
    portalDetails                                     Y
    portalDetailsExpires                              Y
    portalEmail                                       Y
    portalEmailExpires                                Y
    portalEvents                                      Y
    portalEventsExpires                               Y
    portalProgramme                                   Y
    portalProgrammeExpires                            Y
    qmShare                                           Y
    registrationDate                                  Y
    section                                           Y
    subsExpire                                        Y
    subsLevel                                         Y
    userpermissions                                   Y
    
  Scout
    id                           Y
    
  Term
    endDate                                Y
    id                                     Y
    masterTerm                             Y
    name                                   Y
    past                                   Y
    startDate                              Y
*/
/** Class giving access to information held in Online Scout Manager
 *
 * This class handles logging in and out of OSM, and all low-level access to
 * OSM's API.  It also handles concerns which are global to the OSM connection,
 * including maintaining caches of objects (such as Sections and Badges) not
 * related to a particular lower-level object.
 *
 * @property-read int $apiId  Read-only.  The API Id to be used in accessing the
 *           API.  This Id will have been issued to the developer by OSM
 *           support.
 * @property-read Scout[int] $myChildren  Read-only.  Array of the logged-in
 *           user's children.  For leaders who are not also parents this will be
 *           an empty array.
 * @property-read Section|null $section  Read-only.  Current section for this
 *           OSM connection.  For leaders this is initially the current section
 *           from the OSM web site, otherwise it is initially null.  It can be
 *           changed by calling method SetSection.
 * @property-read Section[int] $sections  Read-only.  Array of sections to which
 *           the logged-in user has leader access.  Note the sections containing
 *           the user's own children will not be included unless the user also
 *           has leader access to those sections.
 */
class OSM extends BaseObject
{
	const BADGETYPE_CHALLENGE = "challenge";
	const BADGETYPE_STAGED = "staged";
	const BADGETYPE_ACTIVITY = "activity";
	
	/** Holds the value of the corresponding virtual public property.
   * @var int
   */
	private $apiId = 0;
  
  /** Array giving information about API calls made.
   * @var array  each key is the URL of an API call.  Each value is an array
   *           having two elements: count (the number of times that call has
   *           made) and nano (the total number of seconds used in that call).
   */
  private $apiTimes = array();

  /** Cache of badges known to this session.
   * This cache will be checked when a badge definition is required, thus
   * avoiding repeated API calls for the same badge definition.
   * @var Badge[string]  array indexed by combination of Badge Id and badge version.
   */
  private $badges = array();
  
  /** Handle used for Curl operations on OSM API.
   * @var Object|null  A value of null indicates that no curl handle has been
   *           created yet.
   */
  private $curlHandle = null;
  
  /** The email used to log in to OSM, or null if not logged in.
   * @var string|null
   */
  private $email = null;

  /** The most recent error code from an API operation.
   * @var string
   */   
  public $errorCode = '';
  
  /** The most recent error message from an API operation.
   * @var string
   */
  public $errorMessage = '';
  
	/** Holds the value of the corresponding virtual public property.  A value of
   * null may indicate this has not yet been set (in which case property
   * $section will also be null) or may simply be the value.
   * @var null|Section
   */
  private $section = null;
  
	/** Holds the value of the corresponding virtual public property, or is null
   * to indicate that the API has not been interrogated to find the value yet.
   * @var null|Section[int]
   */
  private $sections = null;

	/** Holds the value of the corresponding virtual public property, or is null
   * to indicate that the API has not been interrogated to find the value yet.
   * @var null|Scout[int]
   */
  private $myChildren = null;
  
  /** Array of sections known to this instance, 
   * This includes both sections to which the user has access and other sections
   * which may have been referenced by, for instance, MyChildren or a shared
   * event.  Contrast this with property $sections.
   * @var Section[int]   indexed by the section identifiers
   */
  private $sectionsCache = array();
	
	/** The API Token issued (with $apiId) by OSM support when they authorise use
   * of the API.
	 * @var string
	 */
	private $token;

  /** The current section from the OSM web interface.
   * This will be initialised to the section the user was last using in the OSM
   * web interface.  For parents who are not leaders this will be null.
   * @var Section|null
   */
  private $uiSection = null;
	
	/** The user ID, issued by the API in response to a username and password,
   * when we log in.
	 * @var int
	 */
	private $userId = null;
	
	/** The user secret, issued by the API in response to a username and password,
   * when we log in.
	 * @var string
	 */
	private $secret = null;
	
	/** The absolute URL which all URLs are relative to.
	 * @var string
	 */
	private $base = 'https://www.onlinescoutmanager.co.uk/';
	
	/** Constructor for an OSM object which can be used to access Online Scout Manager
   *
	 * @param int $apiId API ID, as supplied by OSM support when authorising an application.  If
   *           this parameter is omitted, the constant OSM_API_ID, if defined, will be used instead.
	 * @param string $token Token, as supplied by OSM support when authorising an application.  If
   *           this parameter is omitted, the constant OSM_TOKEN, if defined, will be used instead.
	 */
	public function __construct( int $apiId = null, string $token = null )
  {	// Establish what API Id and Token are to be used to access OSM
    if ($apiId == null && defined( 'OSM_API_ID' )) $apiId = OSM_API_ID;
    $this->apiId = $apiId;
		if ($token == null && defined( 'OSM_TOKEN' )) $token = OSM_TOKEN;
    $this->token = $token;
    if (!$this->apiId) throw new \Exception( "OSM API id not specified" );
    if (!$this->token) throw new \Exception( "OSM token not specified" );
	}

  /** Return a virtual property of the OSM connection.
   *
   * Several private properties of the OSM object are made available outside the
   * class through this method.  These properties can then be initialised by
   * making an API call without the overhead of making an (expensive) API call
   * if the property is never used.
   *
   * @param string $property  The name of the property to fetch.
   * @returns mixed  the value of the requested property (possibly after making
   *           an API call to determine the value).
   */
  public function __get( $property ) {
    switch ($property) {
      case 'apiId':
        // Private variable is set in constructor
        return $this->apiId;
      case 'myChildren':
        // Method MyChildren initialises and returns private variable if rqrd.
        return $this->MyChildren();
      case 'section':
        if ($this->section === null) {
          // Method Sections initialises uiSection variable if rqrd.
          $this->ApiGetUserRoles();
          $this->section = $this->uiSection;
        }
        return $this->section;
      case 'sections':
        // Method Sections fetches list of accessible sections if necessary
        $this->ApiGetUserRoles();
        return $this->sections;
      default:
        throw new \Exception( "unknown property OSM->$property" );
    }
  }
  
  /** Magic method to convert object to string (used wherever an OSM object
   * used in a context requiring a string).
   * @return string  of the form "OSM as <username>".
   */
  public function __toString() {
    return "OSM as $this->userId";
  }
  
	/** Fetch all terms for all accessible sections so they are available for other methods.
   * This method should only be called from class Section.
	 * 
	 * @return void
	 */
  public function ApiGetTerms()
  { $this->ApiGetUserRoles();
    $apiTerms = $this->PostAPI( 'api.php?action=getTerms' );
    foreach ($this->sections as $section)
    { if (isset($apiTerms->{$section->id})) $section->ApiUseGetTerms( $apiTerms->{$section->id} );
      else $section->ApiUseGetTerms( array() );
    }
  }

  /** Call API with action getUserRoles to find accessible sections and many of
   * their properties.
   *
   * @return void
  */
  public function ApiGetUserRoles(): void {
    if ($this->sections === null) {
      $this->uiSection = null;
      $this->sections = array();
      // The following call returns an array of sections to which the current user has access.
      // The API call returns an array of objects, one for each section to which the logged-in user
      // has access.  The contents of each object are described in method
      // Section->ApiUseGetUserRoles
      $apiSections = $this->PostAPI( 'api.php?action=getUserRoles' );
      if (is_array( $apiSections )) {
        foreach ($apiSections as $apiSection) {
          $section = $this->Section( intval( $apiSection->sectionid ) );
          $section->ApiUseGetUserRoles( $apiSection );
          $this->sections[ $section->id ] = $section;
          if ($this->uiSection == null || $apiSection->isDefault)
            $this->uiSection = $section;
        }
      }
    }
  }

  /** Search for a scout by name or email in one or more sections.
   *
   * @param string $firstName  if truthy, only scouts having this string in
   *           their first name will be returned.
   * @param string $lastName  if truthy, only scouts having this string in
   *           their last name will be returned.
   * @param string $email  if truthy, only scouts having this string in their
   *           email address will be returned.
   * @param Section[]|null $sections  array of sections to be searched.  If null
   *           or omitted, all available sections will be returned.
   *
   * @return Scout[]  array of scouts meeting all the criteria specified in the
   *           parameters.
   */
  public function ApiMemberSearch( $firstName, $lastName, $email, $sections ) {
    $args = array();
    if (!$sections) {
      $this->ApiGetUserRoles();
      $sections = $this->sections;
    }
    $args['sections'] = '[' . implode( ',',
                array_map( function( $s ) {return $s->id;}, $sections ) ) . ']';
    if ($firstName) $args['firstname'] = $firstName;
    if ($lastName) $args['lastname'] = $lastName;
    if ($email) $args['email'] = $email;
    $r = $this->PostAPI( 'ext/dashboard/?action=memberSearch', $args );
    $results = array();
    foreach ($r->items as $apiItem) {
      $section = $this->Section( $apiItem->sectionid );
      $section->ApiUseMemberSearch( $apiItem );
      $scout = $section->FindScout( $apiItem->scoutid );
      $scout->SetName( $apiItem->firstname, $apiItem->lastname );
      $results[] = $scout;
    }
    return $results;
  }
 
  /** Forget all cached information.
   *
   * This is used after logout to ensure we don't subsequently return objects
   * which a new logged-in user is not entitled to see.  The only known use-case
   * for this is during unit testing.
   */
  public function ClearCache() {
    foreach ($this->sectionsCache as $section) $section->BreakCache();
    if ($this->myChildren)
      foreach ($this->myChildren as $scout) $scout->BreakCache();
    $this->badges = array();
    $this->sections = null;
    $this->section = null;
    $this->myChildren = null;
    $this->sectionsCache = array();
    $this->uiSection = null;
  }

  /** Set the connection's current section.
   *
   * This doesn't affect OSM's web UI, which keeps its own record of current
   * section used.
   *
   * @param Section|int|null $section  the section to become current.  If the
   *           argument is an integer it is the id of the required section; if
   *           the argument is null or omitted then the current section of the
   *           web UI will be used.
   *
   * @return Section|null  the new current section.  May be null if no section
   *           was specified and the current user is not a leader.
  */
  public function SetSection( $section = null ) {
    if ($section instanceOf Section) $this->section = $section;
    elseif (is_int( $section )) $this->section = $this->Section( $section );
    else {
      if (!$this->sections) $this->ApiGetUserRoles();
      $this->section = $this->uiSection;
    }
    return $this->section;
  }

  /** Finds the object representing a particular badge and version, creating the object if necessary
   * but leaving most properties undefined.
   *
   * @param string $idv  The full Id (including version) of the required badge.
   *
   * @return Badge  the requested Badge object.  This object will have most
   *           properties undefined, but any attempt to read a property will
   *           prompt an API call to attempt to populate it.
   */
  public function Badge( string $idv ): Badge {
    if (!isset( $this->badges[$idv] ))
      $this->badges[$idv] = new Badge( $idv );
    return $this->badges[$idv];
  }

  /** Finds the object representing a particular section, creating the object if
   * necessary.
   *
   * @param int|string $sectionId  the number used in OSM as the unique
   *           identifier for a section.  If a string, must be numeric.
   *
   * @returns Section the object representing the section.  This object may
   *           have most properties undefined, but any attempt to read a
   *           property will prompt an API call to attempt to populate it.
   */
  public function Section( $sectionId ) {
    if (is_string( $sectionId )) $sectionId = intval( $sectionId );
    if (!isset( $this->sectionsCache[$sectionId] )) {
      $this->sectionsCache[ $sectionId ] = new Section( $this, $sectionId );
    }
    return $this->sectionsCache[ $sectionId ];
  }

  /** Finds a scout with the given name.
   *
   * @param string|null $firstName  if non-empty, the method will search for a
   *           scout with this first name.
   * @param string|null $lastName  if non-empty, the method will search for a
   *           scout with this last name.
   *
   * @return Scout|null  returns a current scout (current in any section
   *           accessible to the logged-in user) whose name matches the
   *           arguments, or null if no such scout can be found.  If there are
   *           multiple matches then one of them will be returned.
   */
  public function FindScoutByName( $firstName, $lastName = null ) {
    $a = $this->ApiMemberSearch( $firstName, $lastName, null, null );
    if (count( $a ) > 0) return current( $a );
    return null;
  }

  /** Finds a section with the given name.
   *
   * @param string $name  the name of the section to be returned.
   *
   * @return Section|null  if a section with the given name is accessible to the
   *           logged-in user then it will be returned; otherwise null is
   *           returned.
   */
  public function FindSectionByName( string $name ) {
    $this->ApiGetUserRoles();
    foreach ($this->sections as $section) {
      echo "Checking {$section->name} against $name<br/>\n";
      if ($section->name == $name) return $section;
      echo "No match<br/>\n";
    }
    echo "No more sections against which to check<br/>\n";
    return null;
  }

  /** Finds a section of a given type (beavers cubs etc).
   *
   * @param string $type  the required type of section.  This may be 'waiting',
   *           'beavers', 'cubs', 'scouts', 'explorers' or 'adults'.
   * @param int $n  when omitted, the first section found of the given type is
   *           returned; may be used to request the 2nd, 3rd etc instead.
   */
  public function FindSectionByType( string $type, int $n = 1 )
  { $this->ApiGetUserRoles();
    foreach ($this->sections as $section)
    { if ($section->type == $type && --$n <= 0) return $section;
    }
    if ($type != 'waiting' && $type != 'beavers' && $type != 'cubs' && $type != 'scouts' && $type != 'adults')
      throw new \Exception( "Unknown section type: $type" );
    return null;
  }
  
	/** Check whether we have logged in.
   *
   * Note that this returns a login if we have (during this session, which may
   * cover several requests) presented credentials (an email and password) and
   * have received in return an authorisation token (userid and secret).  There
   * seem to have long lifetimes), nor that it has permission to access anything
   * in particular (which is determined by permissions given by the user to the
   * application in the External Access part of Settings/My Account Details).
	 * 
	 * @return string|null  the email address used to log in.
	 */
	public function IsLoggedIn() {
		return $this->email;
	}
  
  /** Check whether we are logged in as a leader */
  public function IsLoggedInAsLeader() {
    if (!$this->IsLoggedIn()) return false;
    $this->ApiGetUserRoles();
    return count($this->sections) > 0;
  }
	
	/** Authorize the API with the username and password provided
   *
   * This method will always attempt to authorise the login with the OSM
   * website.
	 * 
	 * @param string $email    Email address of user to authorize
	 * @param string $password Password of the user to authorize
	 * 
	 * @return boolean  true iff the login succeeded.
	 */
	public function Login( $email, $password ): bool
  { $apiData = $this->PostAPI( 'users.php?action=authorise',
                                  ['password'=>$password, 'email'=>$email] );
		if (!isset($apiData->secret)) {
			return false;
		}
    $this->secret = $apiData->secret;
		$this->userId = $apiData->userid;
    $this->email = $email;
    $this->ClearCache();
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
    $this->ClearCache();
	}
  
	/** Make an API call to fetch information.
   *
   * Although this method is public, it should only be used from within OSM and related classes.
	 * 
	 * @param string   $url       The URL to query, relative to the base URL
	 * @param string[] $postArgs  The URL parts, encoded as an associative array
	 * @param bool  $useApiCache  true iff the API Cache should be used.  This
   *                            cache will be searched for a result we can use
   *                            instead of actually making the call and, if we
   *                            do actually make the call then the result will
   *                            be saved in the cache.
	 * 
	 * @return string[];
	 */
	public function PostAPI( $url, $postArgs=array(), $useApiCache = false ) {
    $this->errorCode = $this->errorMessage = '';
		if (!$this->curlHandle) $this->curlHandle = curl_init();
  
    // Include API Id and token as POST fields
		$postArgs['apiid'] = $this->apiId;
    $postArgs['token'] = $this->token;
		
    // Include UserId and Secret if they are known (as a result of an earlier authorise request)
		if ($this->userId)
    { $postArgs['userid'] = $this->userId;
		  $postArgs['secret'] = $this->secret;
    }
		
		$data = http_build_query( $postArgs );
    
    // Return cached value if requested and available.
    if ($useApiCache) {
      if (!array_key_exists( $url, $this->apiCache ))
        $this->apiCache[$url] = array();
      if (array_key_exists( $data, $this->apiCache[$url] ))
        return $this->apiCache[$url][$data];
    }
    
    curl_setopt( $this->curlHandle, CURLOPT_URL, $this->base . $url );
		curl_setopt( $this->curlHandle, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $this->curlHandle, CURLOPT_POST, 1 );
		curl_setopt( $this->curlHandle, CURLOPT_CONNECTTIMEOUT, 2 );
		curl_setopt( $this->curlHandle, CURLOPT_RETURNTRANSFER, true );
    if (!isset( $this->apiTimes[$url] ) )
      $this->apiTimes[$url] = (object)['count'=>0, 'nano'=>0];
    $this->apiTimes[$url]->nano -= \microtime( true );
		$msg = curl_exec( $this->curlHandle );
    $this->apiTimes[$url]->nano += \microtime( true );
    $this->apiTimes[$url]->count += 1;
    
    if($msg === false){
      echo "Curl returned an error<br/>\n";
      throw new \Exception( curl_error($this->curlHandle) );
    }
   $out = json_decode($msg);
    if (defined('TRACEOSM')) {
      if (TRACEOSM == 'true') $traceOSM = $_GET['traceosm'] ?? '';
      elseif (TRACEOSM == 'false') $traceOSM = '';
      else $traceOSM = TRACEOSM;
      if ($traceOSM && preg_match( $traceOSM, $url )) {
        echo "\n<h2>", htmlspecialchars( $url ), "</h2>";
        echo "<p>Post Args: ", htmlspecialchars( $data ), "</p>\n";
        echo "<pre>\n", htmlspecialchars( json_encode( $out, JSON_PRETTY_PRINT ) ), "</pre>\n";
        echo "<p>End of response to ", htmlspecialchars( $url ), "</p>\n";
      }
    }

    if (is_object( $out )) {
      if (isset( $out->error )) {
        if (is_string( $out->error )) {
          $this->errorCode = 'Unset';
          $this->errorMessage = $out->error;
        } else {
          $this->errorCode = $out->error->code;
          $this->errorMessage = $out->error->message;
        }
        return null;
      }
      if ($useApiCache) $this->apiCache[$url][$data] = $out;
      return $out;
    } else if ($out === false || $out === null)
      // Logged in user doesn't have permission to use this API so indicate this by returning null
      return null;
    else if (is_array( $out )) {
      if ($useApiCache) $this->apiCache[$url][$data] = $out;
      return $out;
    }
    throw new Exception( "OSM returned unexpected content" );
	}

	/** Make an API call to fetch information.
   *
   * Although this method is public, it should only be used from within OSM and related classes.
	 * 
	 * @param string   $url      The URL to query, relative to the base URL
	 * @param string[] $getArgs  The URL parts, encoded as an associative array
	 * 
	 * @return string[];
	 */
	public function GetAPI($url, $getArgs=array() )
  { assert( false, "Believed unused" );
    if ($getArgs) $url .= '?' . http_build_query( $getArgs );
    
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
  private function MyChildren() {
    if ($this->myChildren == null) {
      $this->myChildren = array();
      $t = $this->PostAPI( "ext/mymember/dashboard/?action=getNextThings" );
      if ($t && $t->data && $t->data->widget_data) {
        foreach( $t->data->widget_data as $sectionId => $children ) {
          $section = $this->Section( (int) $sectionId );
          foreach ($children as $scoutId => $widgets) {
            $this->myChildren[$scoutId] = $section->FindScout( (int) $scoutId );
            $this->myChildren[$scoutId]->isMyChild = true;
          }
        }
      }
    }
    return $this->myChildren;
  }

  /** Checks this OSM object is OK to persist into a new page, and removes parts
   * which should not persist.
   * @param int $apiId  the application identifier, as supplied by OSM support
   *           when authorising the application.  Persistance is permitted only
   *           if this matches the API Id of this object.
   * @param string $token  the token, as supplied by OSM support when
   *           authorising the application.  Persistance is permitted only if
   *           this matches the token of this object.
   *
   * @returns  true iff the apiId and token match.
   */
  public function OkToPersist( $apiId, $token ): bool {
    $this->apiTimes = array();
    return $apiId == $this->apiId && $token == $this->token;
  }

  /** Returns an OSM object which persists via the session.
   *
   * The first time this method is called it will return a new OSM object just
   * like 'new OSM( $apiId, $token )'.  However, it will also save that object
   * and related information in $_SESSION array so that, when execution is
   * terminated, the object will be saved as part of the session state.
   * If a subsequent page calls this method then the OSM object will be
   * reconstituted, including the cached sections, scouts, badges, permissions
   * etc.
   *
   * The state written will include changes made to the returned object up until
   * the time the session is written (usually at the end of execution).  This
   * means that the cache will effectively live through a sequence of pages,
   * significantly reducing the load on the OSM servers.  
   *
   * Note that a new, empty, OSM object will be returned if any of the
   * following are the case:
   * - more than thirty minutes have elapsed
   * - the API Id or token supplied in this call differ from those in the saved
   *   OSM.
   *
	 * @param int $apiId API ID, as supplied by OSM support when authorising an
   *           application.
	 * @param string $token Token, as supplied by OSM support when authorising an
   *           application.
   *
   * @returns OSM an OSM object allowing connection to Online Scout Manager.
   */
  public static function PersistantOSM( int $apiId, string $token ) {
    if (!session_id()) session_start();
    $osm = $_SESSION['OSM'] ?? null;
    if (!$osm || !isset($_SESSION['OSM_EXPIRY']) ||
        $_SESSION['OSM_EXPIRY'] < time() || !($osm instanceof OSM) ||
        !$osm->OkToPersist( OSM_API_ID, OSM_TOKEN )) {
      $osm = new OSM( OSM_API_ID, OSM_TOKEN );
    }
    $_SESSION['OSM_EXPIRY'] = time() + 30 * 60;
    return $_SESSION['OSM'] = $osm;
  }

  /** Print summary of API calls and time taken since OSM object was created.
   */
  public function PrintAPIUsage(): void {
    foreach ($this->apiTimes as $url => $a) {
      echo "\n", number_format( $a->nano, 3 ), " seconds for ", $a->count,
           " calls of ", htmlspecialchars( $url ), "<br/>";
    }
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

/** Represents a Challenge, Activity, Staged or Core Badge.
 *
 * The individual requirements for a badge are represented by objects of class
 * Requirement.
 * The progress of a Scout towards a badge is represented by an object of type
 * BadgeWork.
 *
 * @property-read string $group
 * @property-read string $id
 * @property-read string $name
 * @property-read Requirement[int] $requirements
 * @property-read string $type
 * @property-read string $version
 */
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
  
  /** @var string  url (relative to OSM's base URL) of a picture of this badge.
   */
  private $picture = null;

  /** @var Requirement[]  array of requirements for this badge, indexed by field Id */
  private $requirements;
  
  /** @var integer  Type of badge: numeric codes for CHALLENGE, ACTIVITY, STAGED or CORE */
  private $type;
  const CHALLENGE = 1;
  const ACTIVITY = 2;
  const STAGED = 3;
  const CORE = 4;
  
  /** Additional information about badges we haven't found how to get from the
   * API.
   * For some badges, one does not have to complete all the requirements.  For
   * example, in the beavers' Outdoors badge only two (out of seven) elements
   * of the first area.  This behaviour is fairly unusual, and we have not
   * found how to get the details from the API, so we use instead this manually
   * composed structure.
   * @var int[][][] Array, indexed by section type.  Each element is an array,
   *           indexed by badge name, with elements which are arrays, indexed by
   *           area name, of integers giving the number of elements of that
   *           area which must be completed.
   */
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
  
  /** Unique identifier (including version) for this Badge.
   * @param string $idv
   */
  public function __construct( $idv ) {
    $this->idv = $idv;
  }

  /** Return a virtual property of the Badge.
   *
   * Several private properties of the Badge are made available outside the
   * class through this method.  These properties can then be initialised by
   * making an API call without the overhead of making an (expensive) API call
   * if the property is never used.
   *
   * @param string $property  The name of the property to fetch.
   * @returns mixed  the value of the requested property (possibly after making
   *           an API call to determine the value).
   */
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
  
  /** Magic method to convert object to string (used wherever a Badge is used in
   * a context requiring a string).
   * @return string  simply returns the name of the badge, possibly followed by
   *           the name of the badge group in parentheses.
   */
  public function __toString(): string {
    $s = $this->name;
    if ($this->group) $s .= " ({$this->group})";
    return $s;
  }

  /** Set Badge details from information returned by API call BadgesPyPerson.
   * The API call may return the same badge multiple times (for different
   * scouts) but the badge details should be the same each time.
   *
   * @param stdClass $apiBadge  an element in the 'badges' array returned for
   *           a scout by the API call.
   */
  public function ApiUseBadgesByPerson( $apiBadge ) {
    $this->name = $apiBadge->badge;
    $this->type = $apiBadge->badge_group;
    $this->picture = $apiBadge->picture;
    $this->id = $apiBadge->badge_id;
  }

  /** Populate the private properties of the badge using data returned by API
   * call with action GetBadgeStructure in method Term::Badges.
   *
   * @param \stdClass $apiData  object giving properties of the badge.
   * @param \stdClass $apiTasks object giving requirements for the badge.
   */
  public function ApiUseGetBadgeStructure( $apiData, $apiTasks ) {
    $this->id = $apiData->badge_id;
    $this->version = $apiData->badge_version;
    assert( $this->idv === $apiData->badge_identifier );
    $this->name = $apiData->name;
    $this->group = $apiData->group_name;
    $this->type = $apiData->type_id;
    $this->requirements = array();
    foreach ($apiTasks[0]->rows as $field) {
      if (property_exists( $field, 'tooltip' ))
        $this->requirements[$field->field] = new Requirement( $this, $field );
    }
  }  
}

/** The work done towards a badge by a scout */
class BadgeWork {
  /** @var int  For most badges, this is zero if not awarded and one if
   * the badge has been awarded.  For staged activity badges it gives the level
   * which has been awarded. */
  private $awarded;

  /** @var Date|null  The date the badge was awarded (or, for a staged badge,
   * the date of the highest level awarded so far).  Null if the scout doesn't
   * yet have the badge. */
  private $dateAwarded = null;

  /** @var Badge  the badge for which this object records progress. */
  public  $badge;
  
  /** @var int  For most badges, this is zero if not completed and one if
   * all necessary elements of the badge have been completed.  For staged
   * activity badges it gives the level which has been completed. */
  private $completed;
  
  /** @var string[]  array of progress notes, indexed by the badge's requirement ids. */
  private $progress;
  
  /** @var Scout  the scout who has done this badge work. */
  public $scout;

  /** Constructor for Badgework objects.
   *
   * @param Scout $scout  the scout who is attempting or has attempted the
   *           badge.
   * @param Badge $badge  the badge being attempted.
   */
  public function __construct( Scout $scout, Badge $badge ) {
    $this->scout = $scout;
    $this->badge = $badge;
  }
  
  /** Return a virtual property of the BadgeWork.
   *
   * Several private properties of the BadgeWork are made available outside the
   * class through this method.  These properties can then be initialised by
   * making an API call without the overhead of making an (expensive) API call
   * if the property is never used.
   *
   * @param string $property  The name of the property to fetch.
   * @returns mixed  the value of the requested property (possibly after making
   *           an API call to determine the value).
   */
  public function __get( $property ) {
    switch ($property) {
      default:
        throw new \Exception( "BadgeWork->$property not found" );
    }
  }

  /** Fill properties of this BadgeWork object using information returned by a
   * API call to BadgesByPerson.
   *
   * @param stdClass $apiBadge  an element of the 'badges' array for a scout
   *           featuring in the 'data' array returned by the API call.
   */
  public function ApiUseBadgesByPerson( $apiBadge ) {
    $this->completed = intval( $apiBadge->completed );
    $this->awarded = intval( $apiBadge->awarded );
    if ($apiBadge->awarded_date == -1) $this->dateAwarded = null;
    else $this->dateAwarded = date_create( '@' . $apiBadge->awarded_date );
    $this->fraction = floatval( $apiBadge->status );
  }

  /** Fill properties of this BadgeWork object using information returned by a
   * API call to GetBadgesRecords for one badge for all scouts in a term.
   *
   * @param stdClass $apiBadge  an element of the 'items' array returned by the
   *           API call.  Each such element describes one scout and the work
   *           they have done for the badge being queried.
   */
  public function ApiUseGetBadgeRecords( $apiBadge ) {
    $this->completed = $apiBadge->completed != 0;
    $this->awarded = $apiBadge->awarded != 0;
    $this->progress = array();
    foreach ($this->badge->requirements as $id => $requirement) {
      $propertyName = '_' . $id;
      if (property_exists( $apiBadge, $propertyName ))
        $this->progress[$id] = $apiBadge->$propertyName;
      else $this->progress[$id] = null;
    }
  }

  /** Return the text entered against a particular requirement for this scout's
   * work towards this badge.
   * The badge and scout concerned are given by the corresponding properties.
   *
   * @param Requirement $requirement  the requirement (which must be a
   *           requirement of the badge) whose text is to be returned.
   * @return Tstring  the text, if any, entered against the requirement for the
   *           scout's work towards the badge.  This is the text we see in the
   *           OSM user interface in the spreadsheet of work towards a
   *           particular badge.  Provided the text doesn't start with an 'x'
   *           it indicates that the requirement has been satisfied.
   */
  public function Text( Requirement $requirement ): string {
    assert( $this->badge === $requirement->badge );
    if (!isset( $this->progress[$requirement->id] )) return '';
    return $this->progress[$requirement->id];
  }

  /** Has the specified requirement been met in this badgework (done by a
   * specific scout towards a specific badge)?
   *
   * The requirement is considered met if the badge has been completed or
   * awarded, and also if the text entered against the requirement is present
   * and doesn't start with an 'x'.
   * @param Requirement $requirement  the requirement to be met.  This must be
   *           a requirement of the badge to which this badgework relates.
   * @return bool  Whether the requirement has been met.
   */
  public function HasMet( Requirement $requirement ): bool {
    assert( $this->badge === $requirement->badge );
    if ($this->completed || $this->awarded) return true;
    if (!isset( $this->progress[$requirement->id] )) return false;
    if (substr( $this->progress[$requirement->id], 0, 1 ) == 'x') return false;
    return true;
  }
  
  /** Returns whether the given requirement can be omitted because enough other
   * requirements in the same area have been satisfied.
   * @param Requirement $requirement  the requirement to be considered.  This
   *           must be a requirement of the badge to which this badgework
   *           relates.
   * @return bool  Whether the requirement can be skipped (because enough other
   *           requirements in the same area have been met).  This is computed
   *           without regard to whether the requirement itself has been met.
   */
  public function CanSkip( Requirement $requirement ): bool {
    assert( $this->badge === $requirement->badge );
    $sectionType = $this->scout->section->type;
    // If we don't have a special set of rules for this section, every
    // requirement must be met.
    if (!isset( Badge::$rules[$sectionType] )) return false;
    $rules = Badge::$rules[$sectionType];
    // If we don't have a special rule for this badge, every requirement must be
    // met.
    if (!isset($rules[$this->badge->name])) return false;
    $rules = $rules[$this->badge->name];
    // If we don't have a special rule for this requirement's area, every
    // requirement in the area must be met.
    if (!isset( $rules[$requirement->area] )) return false;
    // Otherwise, count the requirements already met within this requirement's area, and if there
    // are as many as required by the special rule, we don't need to meet this requirement.
    $count = 0;
    foreach ($this->badge->requirements as $r) {
      if ($r->area == $requirement->area && $this->HasMet( $r )) $count++;
    }
    return $count >= $rules[$requirement->area];
  }
}

/** Objects of class Contact act as containers for address & 'phone information
 * for scouts, their primary contacts, their emergency contacts and their
 * doctors.
 *
 * @property string $address1  first line of address
 * @property-read string $address1  second line of address
 * @property-read string $address1  third line of address
 * @property-read string $address4  fourth line of address
 * @property-read string $email1  email for this contact
 * @property-read string $email2  alternative email for this contact
 * @property-read string $firstName  first name of contact (null for Member
 *           contact)
 * @property-read string $lastName   last name of contact (null for Member
 *           contact)
 * @property-read Date $lastUpdated  date this contact was last updated
 * @property-read string $lastUpdatedBy  name of person who last updated this
 *           contact
 * @property-read string $phone1  telephone number for this contact
 * @property-read string $phone2  alternative telephone number for this contact
 * @property-read string $postcode  post code
 */
class Contact extends CustomData
{
  /** Magic method to convert object to string (used wherever a Contact is
   * used in a context requiring a string).
   * @return string  Name of the contact e.g. "Andrew Fisher".  If no last name
   *           is present then the scout's last name will be used.
   */
  public function __toString() {
    return $this->firstName . ' ' . ($this->lastName ?: $this->scout->lastName);
  }
}

/** Custom Data associated with a scout.  The object will support a virtual
 * property for each property supplied by the API (but not that we rename some
 * properties (firstname, lastname, last_updated_by and last_updated_time) to
 * use CamelCases.
 * Each Scout will typically have a CustomData object for each Contact (member,
 * primary1, primary2, emergency or doctor) as well as CustomData objects
 * containing other standard and user-defined properties.
 * Note that if two scouts share the same contact details (e.g. siblings) then
 * each scout will still have distinct contact objects.
 */
class CustomData extends BaseObject
{
  /** @var mixed[]  properties of the contact, indexed by property name.  The
   * properties defined depend upon the contact type (e.g. the 'doctor' contact
   * has a property 'surgery'; also, users can define extra properties with
   * names starting 'cf-'.
   * Note also that we rename properties firstname, lastname, last_updated_by
   * and last_updated_time to use CamelCase. */
  protected $properties = array();
  
  /** @var Scout the scout for whom this is a contact. */
  protected $scout;
  
  /** @var string identifying which contact this is ('member', 'primary1' or
   *           'primary2').
   */
  private $type;
 
   
  /** Magic method to convert object to string (used wherever a CustomData is
   * used in a context requiring a string).
   * @return string  the word CustomData with all the properties and their
   *           values listed in brackets.
   */
  public function __toString() {
    $s = "CustomData (";
    foreach ($this->properties as $property => $value) {
      $s .= "$property = $value,";
    }
    return substr( $s, 0, -1 ) . ")";
  }

  /** Constructor for CustomData objects
   *
   * @param $scout  the scout to whom the CustomData object relates.
   * @param $apiObj the object returned by an 'customdata' API call for the
   *           given scout.
   */
  public function __construct( Scout $scout, \stdClass $apiObj ) {
    $this->scout = $scout;
    foreach ($apiObj->columns as $column) {
      $varName = $column->varname;
      switch ($varName) {
        case 'firstname':
          $varName = 'firstName';
          break;
        case 'lastname':
          $varName = 'lastName';
          break;
        case 'last_updated_by':
          $varName = 'lastUpdatedBy';
          break;
        case 'last_updated_time':
          $varName = 'lastUpdated';
          break;
      }
      if ($column->type == 'last_updated') {
        $this->properties[$varName] = date_create( $column->value );
      } elseif ($column->type == 'select' &&
                (($column->config->options->Yes ?? '') == 'Yes' ||
                 ($column->config->options->No ?? '') == 'No')) {
          $this->properties[$varName] = $column->value == 'Yes';
      } else
        $this->properties[$varName] = $column->value;
    }
  }

  /** Return a virtual property of the CustomData.
   *
   * Several private properties of the CustomData are made available outside the
   * class through this method.  These properties can then be initialised by
   * making an API call without the overhead of making an (expensive) API call
   * if the property is never used.  The set of properties defined depends upon
   * which CustomData is concerned.
   *
   * @param string $property  The name of the property to fetch.
   * @returns mixed  the value of the requested property (possibly after making
   *           an API call to determine the value).
   */
  public function __get( $property ) {
    if (array_key_exists( $property, $this->properties )) {
      return $this->properties[$property];
    }
    switch ( $property ) {
      case 'firstName':
      case 'lastName':
        return $this->scout->{$property};
      case 'scout':
        return $this->scout;
    }
    return $this->GetExtra( $property );
  }

  /** Does this CustomData object support a named field?
   *
   * @param string $property  the name of the property of interest
   *
   * @return bool  true iff this CustomData object supports the named field.
   *          Note, for example, that a the CustomData for additional
   *          information will have very different fields to that for a
   *          Contact, and that a particular section can also define additional
   *          user-defined fields.
   */
  public function FieldEnabled( $property ) {
    if (array_key_exists( $property, $this->properties )) return true;
    return array_key_exists( 'cf_'.$property, $this->properties );
  }

  /** Returns a user-defined field.
   *
   * Usually one can simply access user-defined fields as though they were
   * normal OSM-defined fields of the CustomData object; this method allows you
   * to access a user-defined field even when its name clashes with an
   * OSM-defined field.
   *
   * @param string $property  the name of the field.
   *
   * @returns string  the value of the field
   */
  public function GetExtra( $property ) {
    if (array_key_exists( 'cf_'.$property, $this->properties )) {
      return $this->properties['cf_'.$property];
    }
    throw new \Exception( "Unknown property $property for Contact" );
  }
}

/** An Event which may be attended by one or more Scouts.
 */
class Event extends BaseObject
{ /** @var Section the section containing this event */
  public $section;
  
  /** @var OSM the OSM object used to access this event */
  public $osm;
  
  /** @var int a globally unique identifier for this event */
  public $id;
  
  /** @var Scout[int] an array of members who may attend this event.  This
   *            contains the members to which you can send invitations in OSM's
   *            user interface. */
  private $attendees = null;
  
  /** @var float|null  the cost of the event, or null if the cost is not yet
   *  determined. */
  public $cost;
  
  /** @var Event[]|null  array of events linked to this one by dint of being
   *            shared copies of it.  A null value indicated we haven't yet
   *            enquired whether any such events exist. */
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
  { assert( is_numeric( $apiEvent->eventid ) );;
    $this->section = $section;
    $this->osm = $section->osm;
    $this->id = (int) ($apiEvent->eventid);
    $this->cost = $apiEvent->cost == "-1.00" ? null : floatval( $apiEvent->cost );
    $this->name = $apiEvent->name;
    $this->startDate = date_create( $apiEvent->startdate );
    $this->endDate = $apiEvent->enddate ? date_create( $apiEvent->enddate . " +1 day -1 second" ) :
                                                                                               null;
  }
 
  /** Magic method to convert object to string (used wherever an Event is used
   * in a context requiring a string).
   * @return string  the name of the Event, possibly followed by the start date
   *           in parentheses.
   */
  public function __toString(): string {
    if ($this->startDate) return $this->name . " on " . $this->startDate->format( 'j-M-Y' );
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

  /** Remove references to other objects.
   *
   * Used while clearing the cache of an OSM object to remove anything that may
   * result in a circular reference.  This allows the PHP garbage collecter to
   * be more efficient.
   */
  public function BreakCache() {
    $this->section = null;
    $this->attendees = null;
    $this->linkedEvents = null;
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
        if ($section = $this->osm->Section( (int) $item->sectionid )) {
          if ($event = $section->Event( (int) $item->eventid ) ) $this->linkedEvents[] = $event;
        }
      }
    }
    return $this->linkedEvents;
  }

  /** Calls API to get the structure of an event.
   *
   * Many of the basic details are populated during creation of an Event (but
   * this may have to be re-thought in future if we start using other sources of
   * events).
   * This does, however, populate the $userColumns property of the event which
   * tells us about extra columns added to the sign-up sheet.
   */
  public function ApiGetStructureForEvent() {
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
   * @param string $username  the user-visible name for a column added to the
   *           attendance sheet for this event.  E.g. 'Cost' or 'T-shirt size'.
   * @return string  the internal name used to index the column values in an
   *           attendance.
   */
  public function UserColumn( string $username ) {
    $this->ApiGetStructureForEvent();
    if (array_key_exists( $username, $this->userColumns )) return $this->userColumns[$username];
    return null;
  }
}

/** The attendance (or otherwise) of a Scout at an Event.
 *
 */
class EventAttendance extends BaseObject
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
  
  /** @var string  giving status of this attendance (corresponds to an entry in
   * the 'Members' tab of an Event in the OSM user interface.  Possible values
   * are "", "Invited", "No", "Reserved", "Show in Parent Portal" or "Yes". */
  private $status;
  
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
    $this->scout = $event->section->FindScout( (int) $apiObject->scoutid );
    // Populate scout's fields from information returned by the getAttendance API call.
    $this->scout->ApiUseGetAttendance( $apiObject );
    $this->event = $event;
    $this->status = $apiObject->attending;
    foreach (preg_grep( "/^f_\d+\$/", array_keys( get_object_vars($apiObject) )) as $k) {
      $this->columns[$k] = $apiObject->{$k};
    }
  }

  /** Return a virtual property of the attendance.
   *
   * Several private properties of the Attendance are made available outside the
   * class through this method.  These properties can then be initialised by
   * making an API call without the overhead of making an (expensive) API call
   * if the property is never used.
   *
   * @param string $property  The name of the property to fetch.
   * @returns mixed  the value of the requested property (possibly after making
   *           an API call to determine the value).
   */
  public function __get( $property ) {
    switch ($property) {
      // The following are set in the constructor, so are always valid
      case 'status':
        return $this->status;
      default:
        throw new \Exception( "Attempt to access Event->$property" );
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

/** A patrol within a section.
 */
class Patrol extends BaseObject {
  
  /** @var integer  the id of this patrol.
   * It is believed that, apart from the special ids (-2,-3) for the leaders and
   * young leaders patrols, these ids are unique across the entire system but we
   * do not depend upon this.
   */
  private $id = null;

  /** @var string  the name of the patrol.  Examples might be "Red", "Panther"
   *            or "Leaders".
   */
  private $name = null;

  /** @var int  the total number of points for the patrol.
   */
  private $points = null;

  /** @var Section  the section of which this patrol is a part.
   */
  private $section;

  /** Constructor for Patrol objects.
   *
   * @param string|int $id  the patrol's identifier.  This is believed to be unique
   *           across all sections, except for ids -2 and -3 which refer to the
   *           leader and young leader patrols in every section.  If this is a
   *           string, it must be string representation of an integer.
   * @param Section $section  the section of which the Patrol forms a part.
   */
  public function __construct( $id, Section $section ) {
    assert( is_numeric( $id ) );
    $this->id = is_string( $id ) ? intval( $id ) : $id;
    $this->section = $section;
  }

  /** Return a virtual property of the Patrol.
   *
   * Several private properties of the Patrol are made available outside the
   * class through this method.  These properties can then be initialised by
   * making an API call without the overhead of making an (expensive) API call
   * if the property is never used.
   *
   * @param string $property  The name of the property to fetch.
   * @returns mixed  the value of the requested property (possibly after making
   *           an API call to determine the value).
   */
  public function __get( $property ) {
    switch ($property) {
      case 'id':
      case 'name':
      case 'points':
        if ($this->$property === null ) $this->section->ApiGetPatrols();
        return $this->$property;
      default:
        throw new exception( "Patrol->$property not found" );
    }
  }

  /** Populate properties of the Patrol returned by the API call GetPatrols.
   *
   * @param stdClass $apiPatrol  an element of the 'patrols' array returned by
   *           the GetPatrols API call which contains information about a
   *           patrol.
   */
  public function ApiUseGetPatrols( $apiPatrol ) {
    assert( $this->id == $apiPatrol->patrolid );
    assert( $this->section->id == $apiPatrol->sectionid ||
                            -2 == $apiPatrol->sectionid );
    $this->name = $apiPatrol->name;
    $this->points = intval( $apiPatrol->points );
  }
}

/** A Requirement for a badge (corresponds to a column on the Badge's page in
 *  the OSM user interface.
 */
class Requirement extends BaseObject {
  
  /** Unique Id allocated (by OSM) to this requirement.
   * @var int
   */
  public $id;
  
  /** Badge to which this requirement relates.
   * @var Badge
   */
  public $badge;
  
  /** Name of this requirement.
   * This is the column heading seen in the user interface when filling in
   * badge progress.
   * @var string
   */
  public $name;
  
  /** Description of the requirement.
   * This is the description offered when clicking on the (i) next to the column
   * heading when filling in badge progress.
   * @var string
   */
  public $description;
  
  /** Letter identifying the area into which this requirement falls.
   *
   * The user interface applies the same background colour to requirements in
   * the same area.  Sometimes the scout is offerred a choice of requirements,
   * perhaps needing to fulfil only two out of four requirements in an area.
   * @var string
   */
  public $area;
  
  /** Constructor for a Badge Requirement.
   *
   * @param Badge $badge  the badge for which this is a requirement.
   * @param \stdClass $apiField  an object returned by the API which specifies
   *           the requirement.
   */
  public function __construct( Badge $badge, \stdClass $apiField ) {
    $this->id = intval( $apiField->field );
    $this->badge = $badge;
    $this->name = $apiField->name;
    $this->description = $apiField->tooltip;
    $this->area = $apiField->module;
  }

  /** Magic method to convert object to string (used wherever a Requirement is
   * used in a context requiring a string).
   * @return string  a phrase of the form "{requirement} for {badge} badge"
   */
  public function __toString(): string {
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
 *
 * @property-read string $allergies  Allergies recorded for this scout.
 *           The value of this virtual property is found in CustomData object
 *           containing Essential Information.
 * @property-read string $dietary  Dietary requirements for this scout.
 *           The value of this virtual property is found in CustomData object
 *           containing Essential Information.
 * @property-read string $gender  Scout's gender.  Many values are possible, but
 *           'Male', 'Female' and '' are the most common.
 * @property-read DateTime $lastUpdated the date and time this scout's record
 *           was last updated.
 * @property-read string $lastUpdatedBy  the name of the person who last updated
 *           this scout's record.
 * @property-read string $medical  Medical information (other than allergies)
 *           for this scout.  The value of this virtual property is found in the
 *           CustomData object containing Essential Information.
 * @property-read string $school  School attended by this scout.  The value of
 *           this virtual property is found in CustomData object containing
 *           Essential Information.
 * @property-read bool|null $swimmer  Indicates whether this scout can swim.  A
 *           blank value indicates that we do not know.  The value of this
 *           virtual property is found in CustomData object containing
 *           Essential Information.
 * @property-read string $tetanus  Year of this scout's most recent tetanus
 *           vaccination.  The value of this virtual property is found in
 *           CustomData object containing Essential Information.
 */
class Scout extends BaseObject
{
  /** Has ApiGetIndividual been called to fill in those fields it can?  If the call fails for a
   * particular member this will still be set to true so that we avoid making multiple calls to get
   * data we are not authorised to see. */
  private $apiGotIndividual = false;
  
  /** @var bool  true iff the API has been called to discover all the badges
   * for this scout. */
  private $badgeWorkComplete = false;

  /** @var BadgeWork[string] array of objects (indexed by badge id & version) showing the progress
   *            made by this scout towards various badges.  This array will be populated as required
   *            during calls to method BadgeWork and/or AllBadgeWork.
   */
  private $badgeWork = array();

  /** @var CustomData|null  CustomData object containing the consents given by
   * the parents of the current scout.
   * This property will be set (by method __get calling ApiCustomData) when it
   * is accessed.
   */
  private $consents = null;
  
  /** @var Date|null  Date of birth.  Accessible via __get method which will query the API to populate
   *  this and other properties if access is attempted. */
  private $dob = null;
  
  /** @var Date|null  Date this scout joined Scouting (in any section, anywhere)
   * null if API has not been interrogated for this information.
   */
  private $dateJoinedMovement = null;
   
  /** @var Date|null  Date this scout left the section.  Null may indicate the
   * scout has not left or may indicate the API has not yet been interrogated.
   */
  private $dateLeftSection = null;   

  /** @var Date|null  Date this scout started in the section.
   */
  private $dateStartedSection = null;

  /** @var Contact Contact details for this scout's doctor */
  private $doctor = null;

  /** @var Contact  this scout's emergency contact */
  private $emergency = null;

  /** @var CustomData|null  The Essential information for this scout.  This
   *           object is created and populated by a call of ApiCustomData and
   *           its properties are made available as virtual properties of the
   *           scout.
   */
  private $essentials = null;
  
  /** @var mixed[]  array of additional fields defined by the user */
  private $extra = array();

  /** @var string|null  First name of scout.  Null indicates the API has not yet
   *           been interrogated to discover this.
   */
  private $firstName = null;
 
  /** @var string|null  Attempting to access the virtual property of the same
   *           name will cause this to be set from a CustomData object called
   *           'Additional' (not to be confused with 'Additional Information'.
   */   
  private $gender = null;

  /** @var bool  is true iff method ApiCustomData has been called on this scout
   *           to retrieve all the custom data for the scout (contacts,
   *           essential information, consents, etc)
   */
  private $gotApiCustomData = false;

  /** @var int  Unique id of scout.
   *
   * Note that two Scout objects may exist in different sections with the same
   * id in the case that the same person is a scout in two sections.
   */
  public $id;
 
  /** @var bool true iff this scout is one of the logged-in users children
   *  accessible through 'My Children' in the standard OSM interface.
   */
  public $isMyChild = false;

  /** @var string|null  Last name of scout.  Null indicates the API has not yet
   *           been interrogated to discover this.
   */
  private $lastName = null;

  /** @var DateTime|null  Either gives the value of virtual property
   *           lastUpdated, or is null to indicate that the API doesn't have a
   *           value for that property.
   */
  private $lastUpdated = null;

  /** @var string|null  Either gives the value of virtual property
   *           lastUpdatedBy, or is null to indicate that the API doesn't have a
   *           value for that property.
   */
  private $lastUpdatedBy = null;
  
  /** @var string|null  Attempting to access the virtual property of the same
   *           name will cause this to be set from a CustomData object called
   *           'Essentials'.
   */   
  private $medical = null;

  /** @var Contact Contact details for this scout themself */
  private $member = null;
    
  /** @var OSM the OSM object used to access this event */
  private $osm;
  
  /** @var string  Other useful information about the scout */
  private $other = null;
  
  /** @var Patrol|null  the patrol this member is in, or null to indicate we
   * have not queried the API for this information. */
  private $patrol = null;
  
  /** @var integer|null Permitted value are 0=>Normal, 1=>APL, 2=PL, 3=SPL,
   * null=API not interrogated.
   */
  private $patrolLevel = null;
  
  /** @var string A Globally Unique identifier for the uploaded photo of the
   * scout.  May be used to create the URL of the photograph.
   */
  private $photoGUID = null;

  /** @var Contact  This scout's primary contact (typically a parent) */
  private $primary1 = null;

  /** @var Contact  This scout's alternative primary contact (typically the
   *           other parent) */
  private $primary2 = null;

  /** @var string|null  Attempting to access the virtual property of the same
   *           name will cause this to be set from a CustomData object called
   *           'Essentials'.
   */   
  private $school = null;
  
  /** @var integer  the section in which this scout is a member.  If the same
   * person is a member in two sections (either simultaneously or sequentially)
   * they should be represented by two Scout objects having the same scoutId but
   * different sections.  Of course, if the person has been set up independently
   * in the two sections, rather than being shared between them, the scoutIds
   * will also differ.
   */
  public $section;
  
  /** @var bool|null  Attempting to access the virtual property of the same
   *           name will cause this to be set from a CustomData object called
   *           'Essentials'.
   */   
  private $swimmer;

  /** @var Term|null  a term in which this Scout was current; may be null if no
   * such term exists in the scout's section or if we haven't yet looked for it.
   */
  private $term = null;
  
  /** @var string|null  Attempting to access the virtual property of the same
   *           name will cause this to be set from a CustomData object called
   *           'Essentials'.
   */   
  private $tetanus = null;

  /** Construction function for Scout
   *
   * @param Section $section  the section to which this scout is attached.
   * @param int $scoutId  the unique id of the scout.  Note that although this
   *           id is unique to the scout it may be shared by several Scout
   *           objects representing the same scout in different sections.
   * @param string|null $firstName  the first (given) name of the scout, or null
   *           if not known.
   * @param string $lastName  the last (family) name of the scout, or null if
   *           not known.
   *
   * @return Scout Note that the scout as returned has almost no properties set.
   *           If further information about the scout is available it should be
   *           added by calling one of the Api... methods depending upon which
   *           API was used to discover the scout.
   */
  public function __construct( Section $section, int $scoutId,
                           string $firstName = null, string $lastName = null ) {
    $this->section = $section;
    $this->osm = $section->osm;
    $this->id = $scoutId;
    $this->firstName = $firstName;
    $this->lastName = $lastName;
  }

  /** Return a virtual property of the Scout.
   *
   * Several private properties of the Scout are made available outside the
   * class through this method.  These properties can then be initialised by
   * making an API call without the overhead of making an (expensive) API call
   * if the property is never used.
   *
   * @param string $property  The name of the property to fetch.
   * @returns mixed  the value of the requested property (possibly after making
   *           an API call to determine the value).
   */
  public function __get( $property ) {
    switch ($property) {
      case 'dateJoinedMovement':
      case 'dateLeftSection':
      case 'dateStartedSection':
      case 'dob':
      case 'firstName':
      case 'lastName':
      case 'patrol':
      case 'patrolLevel':
        if ($this->$property === null) $this->ApiGetIndividual();
        return $this->$property;
      case 'patrolLevelAbbr':
        if ($this->patrolLevel === null) $this->ApiGetIndividual();
        return $this->section->PatrolLevelAbbr( $this->patrolLevel );
      case 'patrolLevelName':
        if ($this->patrolLevel === null) $this->ApiGetIndividual();
        return $this->section->PatrolLevelName( $this->patrolLevel );
      case 'gender':
      case 'member':
      case 'primary1':
      case 'primary2':
      case 'emergency':
      case 'doctor':
      case 'consents':
      case 'gender':
      case 'lastUpdated':
      case 'lastUpdatedBy':
       if ($this->$property === null) $this->ApiCustomData();
       if ($this->$property === null) echo "No $property for $this<br/>\n";
       return $this->$property;
      case 'allergies':
      case 'dietary':
      case 'medical':
      case 'other':
      case 'school':
      case 'swimmer':
      case 'tetanus':
        if ($this->essentials === null) $this->ApiCustomData();
        if ($this->essentials->FieldEnabled( $property )) {
          return $this->essentials->$property;
        }
        return null;
      default:
        $this->ApiCustomData();
        if ($this->extra->FieldEnabled( 'cf_'.$property )) {
          return $this->extra->{'cf_'.$property};
        }
        throw new \Exception( "Cannot read property $property for $this" );
    }
  }

  /** Magic method to convert object to string (used wherever a Scout is used in
   * a context requiring a string).
   * @return string  the name of the scout, if available, otherwise returns id
   *           in the form "Scout {id}".
   */
  public function __toString()
  { if ($this->firstName === null) return "Scout {$this->id}";
    return $this->Name();
  }
  
  /** Returns scout's age, on a particular date, in complete months.  I.e. if
   * their twelve birthday is the next day the result will be 143.
   *
   * @param DateTime|null $d  the date for which the age should be computed.  If
   *           null or omitted, today's date will be used.
   * @return int  the age of the scout in complete months, or null if this is
   *           not known.
   */
  public function AgeInMonths( DateTime $d = null ) :int {
    if (!$this->dob) return null;
    if (!$d) $d = date_create();
    $age = $d->diff( $this->dob );
    return 12*$age->y + $age->m;
  }

  /** Call API endpoint to fetch list of badges held (or being worked on) by
   * this scout.  Minimal information about progress on each badge is retrieved.
   *
   * Warning: this method is not full implemented.  You might choose to use
   * Term->ApiBadgesByPerson as an alternative where the logged-in user has
   * leader access.
   */
  public function ApiBadgesGetSummary() {
    $r = $this->osm->PostAPI( "ext/mymember/badges/?action=getSummary",
                              array( 'member_id'=>$this->id,
                                     'section_id'=>$this->section->id ) );
    throw new \Exception( "Not implemented" );
  }
  
  /** Fetches custom data about the member.  This includes the member's contact
   * details, together with their primary, secondary and emergency contacts etc.
   */
  private function ApiCustomData() {
    if ($this->gotApiCustomData) return;
    if ($this->section->Permissions( 'member' ) > 0)
      $apiData = $this->osm->PostAPI( "ext/customdata/?action=getData&section_id={$this->section->id}",
                              array( 'associated_id'=>$this->id, 'associated_type'=>'member',
                                     'context'=>'members', 'group_order'=>'section' ) );
    elseif ($this->isMyChild) {
      $apiData = $this->osm->PostAPI( "ext/customdata/?action=getData&section_id={$this->section->id}",
                              array( 'associated_id'=>$this->id, 'associated_type'=>'member',
                                     'context'=>'mymember', 'group_order'=>'section' ) );
    }
    if ($apiData && $apiData->data) {
      foreach ($apiData->data as $apiGroup) {
        switch ($apiGroup->group_id) {
          case 1:
            $this->primary1 = new Contact( $this, $apiGroup );
            break;
          case 2:
            $this->primary2 = new Contact( $this, $apiGroup );
            break;
          case 3:
            $this->emergency = new Contact( $this, $apiGroup );
            break;
          case 4:
            $this->doctor = new Contact( $this, $apiGroup );
            break;
          case 5: // Fields added by user
            $this->extra = new CustomData( $this, $apiGroup );
            break;
          case 6:
            $this->member = new Contact( $this, $apiGroup );
            break;
          case 7:
            $additional = new CustomData( $this, $apiGroup );
            $this->gender = $additional->gender;
            break;
          case 8:
            $singular = new CustomData( $this, $apiGroup );
            $this->lastUpdatedBy = $singular->lastUpdatedBy;
            $this->lastUpdated = $singular->lastUpdated;
            break;
          case 9:
            $this->essentials = new CustomData( $this, $apiGroup );
            break;
          case 10:
            $this->consents = new CustomData( $this, $apiGroup );
            break;
        }
      }
    }
    $this->gotApiCustomData = true;
  }

  /** Populate properties of the scout supplied by API call GetAttendance.
   *
   * @param \stdClass $apiObject  an object returned by the API containing basic information about the
   *           member.  We take a particular interest in its following properties:
   *           scoutid string giving unique id for this member.  Provided the member has been moved
   *                     or shared between sections (rather than have data re-entered) this id will
   *                     be the same in all sections.
   *           firstname string
   *           lastname string
   *           patrolid  numeric string identifying the scout's patrol.
   */
  public function ApiUseGetAttendance( \stdClass $apiObject ) {
    assert( $this->id == $apiObject->scoutid );
    $this->firstName = $apiObject->firstname;
    $this->lastName = $apiObject->lastname;
    $this->patrol = $this->section->Patrol( $apiObject->patrolid );
  }
  
  /** Populate details of a scout and his badge work using the result of an API
   * call GetBadgeRecords.
   *
   * @param Badge $badge  the badge to which the data relate.
   * @param \stdClass $apiItem  the object returned by the API.
   */
  public function ApiUseGetBadgeRecords( Badge $badge, \stdClass $apiItem ) {
    $this->SetName( $apiItem->firstname, $apiItem->lastname );
    if (!array_key_exists( $badge->idv, $this->badgeWork ))
      $this->badgeWork[$badge->idv] = new BadgeWork( $this, $badge );
    $this->badgeWork[$badge->idv]->ApiUseGetBadgeRecords( $apiItem );
  }
  
  /** Make a call to API post ext/members/contact/?action=getIndividual and use the result to
   * populate properties of the scout.
   */
  public function ApiGetIndividual() {
    /* The API call will return an object with the following properties:
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
    * patrolid  the id of the patrol in which this scout is a member.  Patrols
    *           are specific to a section, except ids '-2' and '-3' which are
    *           is the leaders and young leaders patrols in all sections.
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
        $this->patrol = $this->section->Patrol( $apiData->patrolid );
        $this->patrolLevel = intval( $apiData->patrolleader );
        $this->dateJoinedMovement = date_create( $apiData->started );
        $this->dateStartedSection = date_create( $apiData->startedsection );
        $this->dateLeftSection = $apiData->enddate === null ? null :
                                 date_create( $apiData->enddate );
    } }
  }
  
  /** Populate properties of the scout, including badgework, returned by a call
   * of ApiBadgesByPerson for a term when this scout is a member.
   *
   * This method also creates Badge objects as required.
   *
   * @param stdClass $apiScout  an element of the 'data' array returned by the
   *           API.  Each such element gives information about a single scout
   *           and all their badge work (but omits details about exactly which
   *           requirements have been met).
   */
  public function ApiUseBadgesByPerson( $apiScout ) {
    $this->firstName = $apiScout->firstname;
    $this->lastName = $apiScout->lastname;
    assert( $this->id == (int) $apiScout->scout_id );
    $this->photoGUID = $apiScout->photo_guid;
    $this->patrol = $this->section->Patrol( $apiScout->patrolid );
    $this->patrolLevel = $apiScout->patrolleader;
    $this->dob = date_create( $apiScout->dob );
    assert( $this->section->id === (int) $apiScout->sectionid );
    foreach ($apiScout->badges as $apiBadge) {
      $badge = $this->osm->Badge( $apiBadge->badge_identifier );
      $badge->ApiUseBadgesByPerson( $apiBadge );
      if (!array_key_exists( $badge->id, $this->badgeWork))
        $this->badgeWork[$badge->id] = new BadgeWork( $this, $badge );
      $this->badgeWork[$badge->id]->ApiUseBadgesByPerson( $apiBadge );
    }
  }
  
  /** Populate properties using the information in an element of the items array
   * returned by API post ext/members/contact/?action=getListOfMembers for a
   * section in a particular term.
   *
   * @param \stdClass $apiItem  the object returned by the API.  This object
   *           will have the following properties:
   * * firstname should match existing
   * * lastname  should match existing
   * * photo_guid  not currently used
   * * patrolid  id number of scout's patrol in their section (-2 for leaders).  Should match
   *           existing.
   * * name of patrol  e.g. 'Blue 6er'.  Not currently used.
   * * sectionid id number of scout's section.  Should match existing.
   * * enddate   date scout left this section (but may have moved to another section).
   * * age       e.g. '10 / 5'.  Not used, but can be derived from date of birth.
   * * patrol_role_level_label  e.g. 'Sixer'.  Not currently used.
   * * active    boolean.  Not currently used.
   * * scoutid   will match existing
   */
  public function ApiUseGetListOfMembers( \stdClass $apiItem ) {
    $this->firstName = $apiItem->firstname;
    $this->lastName = $apiItem->lastname;
    $this->patrol = $this->section->Patrol( $apiItem->patrolid );
    assert( $this->section->id == $apiItem->sectionid );
    assert( $this->id == $apiItem->scoutid );
  }

  /** What progress is this scout making towards a particular badge?
   *
   * @param Badge $badge  the badge to interrogate.
   * @returns BadgeWork | null  An object showing the the progress this scout is
   *           making towards the specified badge, or null if no such progress
   *           is recorded.
   */
  public function BadgeWork( $badge ) {
    if (!array_key_exists( $badge->idv, $this->badgeWork )) {
      $this->Term()->ApiGetBadgeRecords( $badge );
      if (!array_key_exists( $badge->idv, $this->badgeWork )) $this->badgeWork[$badge->idv] = null;
    }
    return $this->badgeWork[$badge->idv];
  }
  
  /** What Badges has this scout started (including completed badges)?
   */
  public function AllBadgeWork() {
    if (!$this->badgeWorkComplete) {
      if ($this->IsChildofUser()) {
        $this->ApiBadgesGetSummary();
      }
      elseif ($this->section->HasBadgePermission()) {
        $this->Term()->ApiBadgesByPerson();
      }
      else throw new \Exception( "You have not given Badges permission to this app in {$this->section}" );
    }
    return $this->badgeWork;
  }

  /** Remove references to other objects.
   *
   * Used while clearing the cache of an OSM object to remove anything that may
   * result in a circular reference.  This allows the PHP garbage collecter to
   * be more efficient.
   */
  public function BreakCache() {
    $this->osm = null;
    $this->section = null;
    $this->badgeWork = array();
  }

  /** Can this scout skip the given requirement because enough other
   * requirements in the same area have been satisfied?
   *
   * @param Requirement $requirement  the requirement about which we are enquiring
   *
   * @return bool true iff more work is needed.  Note that no work is needed if the
   *           badge has been completed or awarded, if the work has been done,
   *           or if sufficient other work in the same group of requirements has
   *           been done (this last only for some badges).  False otherwise.
   */
  public function CanSkip( Requirement $requirement ) {
    $work = $this->BadgeWork( $requirement->badge );
    if ($work) return $work->CanSkip( $requirement );
    return false;
  }

  /** Returns an email for a given contact.
   *
   * @param \stdClass|null $contact  an object giving the data associated with a
   *            particular contact of this scout.
   * @return string|null  An email address for the given contact, or null if no
   *           email was found (or the contact itself was null).
   */   
  public function ContactEmail( $contact )
  { if (!$contact) return null; 
    if ($contact->email1) return $contact->email1;
    return $contact->email2;
  }

  /** Returns a contact name for the given scout.
   *
   * @return string  A best guess as to a suitable contact name for the scout.
   *           For an adult scout this will be the scout's own name; otherwise
   *           it will be a primary contact name if possible.
   */   
  public function ContactName() {
    if ($this->IsAdult()) return $this->Name();
    $contact = $this->PreferredContact();
    if ($contact == null || !$contact->firstName) return "Parent of {$this}";
    if (!$contact->lastName) return $contact->firstName . ' ' . $this->lastName;
    return $contact->firstName . ' ' . $contact->lastName;
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
    return $this->ContactEmail( $preferred );
  }

  /** Return some additional information (user-defined fields added for each
   * scout in a section).
   * It is normally possible to retrieve such fields as though they were
   * properties of the scout, but that will not work if they have names which
   * clash with standard properties.
   *
   * @param string $property  the name of the required field
   *
   * @returns mixed  the value of the required field.
   */
  public function GetExtra( $property ) {
    if ($this->extra->FieldEnabled( $property )) return $this->extra->$property;
    return null;
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
  
  /** Is this scout a child of the logged-in user (in the Parent Portal sense)?
   */
  public function IsChildOfUser() {
    foreach ($this->osm->myChildren as $child) {
      if ($child === $this) return true;
    }
    return false;
  }

  /** Is this scout an adult?
   *
   * @returns bool  true iff this scout is an adult (i.e. in the leaders patrol
   *           or in an adult section.
   */
  public function IsAdult()
  { if (($this->patrol->id??0) == -2) return true;
    if ($this->section->type == 'adults') return true;
    return false;
  }
  
  /** Is this scout a Young Leader?
   *
   * @returns bool  true if this scout is in the young leaders patrol.
   */
  public function IsYoungLeader()
  { if (($this->patrol->id??0) == -3) return true;
    return false;
  }
  
  /** Full name of scout.
   *
   * @returns string  Full name (first and last names, separated by a space).
   */
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
    if ($contact && $contact->lastName && (stripos( $this->lastName, $contact->lastName ) !== false
                                       || stripos( $contact->lastName, $this->lastName ) !== false))
      return $this->firstName;
    return $this->Name();
  }

  /** Gives the short name of this scout's patrol level
   *
   * @returns string  the short name of this scout's patrol level e.g. '2nd' or
   *           'PL' depending upon the type of section and the level of the
   *           scout.
   */
  public function PatrolLevelAbbr() {
    if ($this->patrolLevel === null) $this->ApiGetIndividual();
    return $this->section->PatrolLevelAbbr( $this->patrolLevel );
  }

  /** Gives the full name of this scout's patrol level
   *
   * @returns string  the full name of this scout's patrol level e.g. 'Seconder'
   *           or 'Patrol Leader' depending upon the type of section and the
   *           level of the scout.
   */
  public function PatrolLevelName() {
    if ($this->patrolLevel === null) $this->ApiGetIndividual();
    return $this->section->PatrolLevelName( $this->patrolLevel );
  }

  /** The name of the patrol this scout is in.
   *
   * @returns string  the name of the patrol.
   */
  public function PatrolName() {
    if ($this->patrol === null) $this->ApiGetIndividual();
    if ($this->patrol === null) return null;
    return $this->patrol->name;
  }

  /** The URL of a photograph of this scout.
   *
   * @param bool $small  If this argument is given, and truthy, then a URL for a
   *           small (100x100 pixel) photo will be returned.  Otherwise the URL
   *           for a large (125x125 pixel) photo will be returned.
   *
   * @returns string  the desired URL, including the scheme and server.
   */
  public function PhotoUrl( bool $small = false ) {
    $size = $small ? 100 : 125;
     return 'https://www.onlinescoutmanager.co.uk/sites/' .
            'onlinescoutmanager.co.uk/public/member_photos/' .
            floor( $this->id/1000 ) .
            "000/{$this->id}/{$this->photoGUID}/{$size}x{$size}_0.jpg";
  }

  /** The preferred contact for the scout.
   *
   * @return Contact|null  returns one of the primary contacts.  The method
   *           will prefer a contact with an email and, other things being
   *           equal, will prefer the first primary contact.
   */
  public function PreferredContact()
  { $this->ApiCustomData();
    if ($this->IsAdult()) {
      $contact = $this->member;
      if ($this->ContactEmail($contact)) return $contact;
    }
    $contact = $this->primary1;
    if ($this->ContactEmail($contact)) return $contact;
    $contact = $this->primary2;
    if ($this->ContactEmail($contact)) return $contact;
    return $this->primary1;
  }

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

  /** Set the scout's first and last names.
   *
   * This method cannot be used to change the scout's name, simply to set it after an API call that
   * may be the first reference to the scout.
   * @param string $firstName
   * @param string $lastName
   */
  public function SetName( $firstName, $lastName ) {
    if ($firstName) {
      assert( !$this->firstName || $this->firstName == $firstName );
      $this->firstName = $firstName;
    }
    if ($lastName) {
      assert( !$this->lastName  || $this->lastName == $lastName );
      $this->lastName = $lastName;
    }
  }

  /** Returns a term in which this scout was active.  The logged-in user must
   * have leader access to the scout's section.
   * @returns Term|null  the most recent term in which this scout was active, or
   *           a future term if the scout was not active in a current or past
   *           term, or null if the scout is not.
   */
  public function Term() {
    if ($this->term === null) {
      $terms = $this->section->Terms();
      //echo "List of terms for $this is ", implode( ",", $terms ), "\n";
      foreach ($this->section->Terms() as $term) {
        if (array_key_exists( $this->id, $term->Scouts() )) {
          $this->term = $term;
          break;
        }
      }
    }
    return $this->term;
  }
}

/** A section within a group.
 *
 * The amount of information actually available about a section will depend upon
 * the permissions granted (by OSM) to the current user.  In particular,
 * a parent may have access to no more than the Id of a section.
 *
 * @property-read bool $hasLeaderPermission  true iff the logged-in user has
 *           leader access to this section.
 * @property-read int|null $meetingDay.  The day of the week (1=Mon, 2=Tue, etc)
 *           on which the section holds its usual weekly meetings, or null if
 *           this has not been specified.
 * @property-read bool $portalBadges.  True iff this section has subscribed to
 *           the add-on allowing parents to view badge progress.
 * @property-read bool $portalEmail.  True iff this section has subscribed to
 *           the add-on allowing leaders to send attachments with emails and to
 *           view sent emails.
 * @property-read bool $portalEvents.  True iff this section has subscribed to
 *           the add-on allowing leaders to invite parents to events and
 *           allowing parents to sign-up for events.
 * @property-read bool $portalPersonal.  True iff this section has subscribed to
 *           the add-on allowing parents to view and amend personal and contact
 *           details.
 * @property-read bool $portalProgramme.  True iff this section has subscribed
 *           to the add-on allowing parents to see the programme. 
 * @property-read DateTime $registrationDate.  The date this section was first
 *           registered on OSM.
 * @property-read DateTime $subscriptionExpires.  The date on which this
 *           section's current OSM subscription expires.
 * @property-read int $subscriptionLevel.  The level of OSM subscription for
 *           this section.  Possible values are 1, 2 or 3 for bronze, silver and
 *           gold respectively.
 */
class Section extends BaseObject
{ /** @var int The Id by which this Section is known to the OSM API. */
  private $id;
  
  /** @var bool  true iff the API call GetPatrols has been made for this
   *            section.
   */
  private $apiGotPatrols = false;
  
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

  /** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property.
   */
  private $hasLeaderPermission = null;

	/** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property, which may be null.
   * @var null|int
   */
  private $meetingDay = null;
  
  /** @var string The name of this section. */
  private $name;
  
  /** @var OSM  the object through which we are accessing OSM. */
  private $osm;
  
  /** Array of Patrol objects for the patrols in this section. */
  private $patrols = array();

	/** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property.
   * @var null|bool
   */
  private $portalBadges = null;

	/** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property.
   * @var null|bool
   */
  private $portalEmail = null;

	/** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property.
   * @var null|bool
   */
  private $portalEvents = null;

	/** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property.
   * @var null|bool
   */
  private $portalPersonal = null;

	/** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property.
   * @var null|bool
   */
  private $portalProgramme = null;

	/** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property.
   * @var null|DateTime
   */
  private $registrationDate = null;

  /** @var Scout[]  array of scouts, indexed by id, in this section.
   * This array is not necessarily complete, and may be added to by method
   * Scout.
   */
  private $scouts = null;

	/** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property.
   * @var null|DateTime
   */
  private $subscriptionExpires = null;

	/** Set by ApiUseGetUserRoles to the value of the corresponding virtual public
   * property.
   * @var null|int
   */
  private $subscriptionLevel = null;

  /** @var null|Term[] An array of terms for this section, indexed by integers 0.. */
  private $terms = null;
  
  /** @var Term|null  the currently selected term for this section.  By default, this will be the
   * most recent term.
   */
  private $term = null;
  
  /** @var $type string The type (waiting, beavers, cubs, scouts or adults) of the section. */
  private $type;

  /** @var \stdClass|null  Object having properties defining the permissions of the logged-in user in
   * various areas of OSM.  Note these permissions may not be valid through the API (cf.
   * $apiPermissions).  A null value indicates we have not interrogated the API to discover the
   * permissions. */
  private $userPermissions = null;
 
  /** Constructor for an OSM Section
   * 
   * This constructor (and 'new Section') should be called only from class OSM.
   *
   * @param OSM $osm    the OSM object used to create this section.
   * @param int $sectionId  the unique identifier of the section
  */
  public function __construct( OSM $osm, int $sectionId ) {
    $this->osm = $osm;
    $this->id = $sectionId;
  }

  /** Return a virtual property of the Section.
   *
   * Several private properties of the Section are made available outside the
   * class through this method.  These properties can then be initialised by
   * making an API call without the overhead of making an (expensive) API call
   * if the property is never used.
   *
   * @param string $property  The name of the property to fetch.
   * @returns mixed  the value of the requested property (possibly after making
   *           an API call to determine the value).
   */
  public function __get( $property ) {
    if (property_exists( self::class, $property ) && $this->{$property} !== null)
      return $this->{$property};
    switch ($property) {
      case 'patrols':
       if (!$this->apiGotPatrols) $this->ApiGetPatrols();
       return $this->patrols;
      case 'groupId':
      case 'groupName':
      case 'meetingDay':
      case 'name':
      case 'type':
        if ($this->$property === null) $this->osm->ApiGetUserRoles();
        if ($this->property !== null) return $this->$property;
        // Fall through to treat as error.
      default:
        throw new \Exception( "Section->$property not found or null" );
    }
  }
  
  /** Magic method to convert object to string (used wherever a Section is used
   * in a context requiring a string).
   * @return string  simply returns the name of the section.
   */
 public function __toString()
  { return $this->name;
  }
 
  /** Interrogate API to find details of patrols in this section.
   *
   * A patrol may be created with no more information about it than it's ID and
   * section.  This method will be called if a list of patrols is required, or
   * if further detail is required about a patrol.
   */  
	public function ApiGetPatrols() {
		if (!$this->apiGotPatrols) {
      $apiData = $this->osm->PostAPI( 'users.php?action=getPatrols&sectionid='. $this->id );
      foreach ($apiData->patrols as $apiPatrol) {
        $patrol = $this->Patrol( $apiPatrol->patrolid );
        $patrol->ApiUseGetPatrols( $apiPatrol );
      }
    }
  }

  /** Initialise the list of terms for this section
   *
   * This method should be called only from the ApiGetTerms method of class OSM.
   * @param \stdClass[] $apiTerms  an array of objects, each having properties copied from the JSON
   *           returned from the API.
  */
  public function ApiUseGetTerms( array $apiTerms )
  { $this->terms = array();
    foreach ($apiTerms as $apiTerm)
    { $this->terms[] = new Term( $this, $apiTerm );
    }
  }

  /** Populate properties of this section using information from a call to
   * api.php?action=getUserRoles.
   *
   * @param \stdClass $apiObj  one of the objects returned by the API call.  See
   *           below for a detailed description.
   *
   * @return void
   */
  public function ApiUseGetUserRoles( \stdClass $apiObj ) {
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
    assert( $this->id == intval( $apiObj->sectionid ) );
    $this->hasLeaderPermission = true; // Note logged-in user has Leader access to this section
    //echo "Populating section {$apiObj->sectionname}<br/>\n";
    $config = $apiObj->sectionConfig;
    $this->groupId = intval( $apiObj->groupid );
    $this->groupName = $apiObj->groupname;
    $this->meetingDay = ['Mon'=>1, 'Tue'=>2, 'Wed'=>3, 'Thu'=>4, 'Fri'=>5,
                        'Sat'=>6, 'Sun'=>7][$config->meeting_day??0] ?? null;
    $this->name = $apiObj->sectionname;
    $this->portalBadges = $config->portal->badges??0 == 1;
    $this->portalEmail = $config->portal->emailbolton??0 == 1;
    $this->portalEvents = $config->portal->events??0 == 1;
    $this->portalPersonal = $config->portal->details??0 == 1;
    $this->portalProgramme = $config->portal->programme??0 == 1;
    $this->registrationDate = date_create( $apiObj->regdate );
    $this->subscriptionLevel = $config->subscription_level;
    $this->subscriptionExpires = date_create( $config->subscription_expires );
    $this->type = $apiObj->section;
    $this->userPermissions = $apiObj->permissions;
  }

  /** Use data from a MemberSearch API call to set properties of a section.
   *
   * @param stdClass $apiData  an element from the items array returned by the
   *           MemberSearch API call.
   */
  public function ApiUseMemberSearch( \stdClass $apiData ) {
    if ($this->type) assert( $this->type == $apiData->section_type );
    $this->type = $apiData->section_type;
    if ($this->name) assert( $this->name == $apiData->sectionname );
    $this->name = $apiData->sectionname;
  }

  /** Remove references to other objects.
   *
   * Used while clearing the cache of an OSM object to remove anything that may
   * result in a circular reference.  This allows the PHP garbage collecter to
   * be more efficient.
   */
  public function BreakCache() {
    if ($this->events) {
      foreach ($this->events as $event) $event->BreakCache();
      $this->events = null;
    }
    $this->osm = null;
    if ($this->scouts) {
      foreach ($this->scouts as $scout) $scout->BreakCache();
      $this->scouts = null;
    }
    if ($this->terms) {
      foreach ($this->terms as $term) $term->BreakCache();
      $this->terms = null;
    }
  }

  /** Fetch list of Events for this section, not including archived events
   *
   * @return Event[int]
   */
  public function Events() {
    if ($this->events === null) {
      $this->events = array();
      // Note: the alternative ext/events/summary/?action=get returns less
      // information about each event and requires both a section and term id,
      // so we don't use it here.  The additional information it provides on numbers
      // accepted etc we can get if required.
      $apiEvents = $this->osm->PostAPI( "events.php?action=getEvents&sectionid={$this->id}" );
      if ($apiEvents) foreach ($apiEvents->items as $apiEvent) {
        $event = new Event( $this, $apiEvent );
        $this->events[$event->id] = $event;
      }
    }
    return $this->events;
  }

  /** Find the event with the given Id
   *
   * @param int $eventId  the id of the required event.
   * @return Event|null  the desired Event provided it is an event in this
   *           section; otherwise null.
   */
  public function Event( int $eventId )
  { $this->Events();
    return $this->events[$eventId] ?? null;
  }
  
  /** Returns the object representing a particular scout in this section.
   *
   * @param int|string $scoutId  the integer (or numeric string) uniquely
   *           identifying this scout.  No check is made whether this is
   *           actually a scout in this section: this will become apparent if an
   *           attempt is made to read properties which the API will be unable
   *           to supply.
   *
   * @returns Scout  object representing the specified scout in this section.
   *           If the same person is a member in several sections then they will
   *           be represented by a different object in each section, all having
   *           the same scoutId.
   */
  public function FindScout( $scoutId )
  { if (is_string( $scoutId ) && is_numeric( $scoutId ))
      $scoutId = intval( $scoutId );
    assert( is_int( $scoutId ) );
    if (!isset( $this->scouts[$scoutId] ))
      $this->scouts[$scoutId] = new Scout( $this, $scoutId );
    return $this->scouts[$scoutId];
  }

  /** Finds a scout by name in the current section
  public function FindScoutByName( $firstName, $lastName = null, $when = null ) {
    if ($when) {
      $term = $this->TermAt( $when );
      return $term->FindScoutByName( $firstName, $lastName );
    }
    foreach ($this->Terms() as $term) {
      if ($scout = $term->FindScoutByName( $firstName, $lastName ))
        return $scout;
    }
    return null;
  }
  
  /** Return full name (including Group) of section
   *
   * @return string Full name of section in the form "GroupName: SectionName"
  */
  public function FullName()
  { return $this->groupName . ': ' . $this->name;
  }

  /** Has the current logged-in user permission to read badge information in
   * this section?
   *
   * @returns bool  true if user can read badge information; false otherwise.
   */   
  public function HasBadgePermission() {
    return $this->HasLeaderPermission() && $this->Permissions( "badge" ) > 0;
  }

  /** Does the current logged-in user have leader access (as opposed to parental
   * access) to this section.
   *
   * @returns bool  true if user has a Leader's login; false if they have just a
   *           parent's login.
   */
  public function HasLeaderPermission() {
    if ($this->hasLeaderPermission === null) {
      $this->hasLeaderPermission = false;
      $this->osm->ApiGetUserRoles();
    }
    return $this->hasLeaderPermission;
  }

  /** Return the OSM object which created this Section.
   *
   * @return OSM
   */  
  public function OSM() {
    return $this->osm;
  }

  /** Returns the patrol with the given id.
   *
   * Note that, while the patrol should be one which exists in OSM, this is not
   * necessarily checked during this call and a non-existant id may not result
   * in an error until an API call is prompted by an attempt to access its
   * properties.
   *
   * @param $patrolId  This must be the id of a patrol which is defined for this
   *           section, or a non-numeric value.
   * 
   * @returns Patrol|null  null is returned if the argument was not a valid
   *           number.
   */
  public function Patrol( $patrolId ) {
    if (!is_numeric( $patrolId )) return null;
    if (!array_key_exists( $patrolId, $this->patrols ))
      $this->patrols[$patrolId] = new Patrol( $patrolId, $this );
    return $this->patrols[$patrolId];
  }

  /** Translate a patrol level code into an abbreviation.  Note that in an adult
   * section, some patrol level codes (those for non-leader roles such as
   * 'Chair') do not have abbreviations.
   *
   * Note that these abbreviations were harvested from the JavaScript returned
   * by /ext/generic/startup/?action=getData called from head.js in the OSM web
   * dashboard.  No API for fetching them is known.
   *
   * @param int $patrolLevel  the code used by OSM to represent a certain role
   *           within a patrol, six, lodge or group.
   * @returns string  the abbreviation for the role.
   */
  public function PatrolLevelAbbr( int $patrolLevel ) {
    if ($patrolLevel == 0) return '';
    $abbrs = array( 'adults' =>[1=>'AL',  2=>'SL', 3=>'YL',    4=>'SA', 5=>'OH',
                                6=>'GSL', 7=>'',   8=>'',      9=>'',  10=>'',
                               11=>'',   12=>'',  13=>'AGSL', 14=>''],
                    'beavers'=>[1=>'ALL', 2=>'LL',  3=>'SLL'],
                    'cubs'   =>[2=>'2nd', 3=>'6er', 3=>'S6er'],
                    'scouts' =>[1=>'APL', 2=>'PL',  3=>'SPL']
                    );
    return $abbrs[$this->type][ $patrolLevel ] ?? '';
  }
  
  /** Translate a patrol level code into a descriptive name.
   *
   * Note that these names were harvested from the JavaScript returned by
   * /ext/generic/startup/?action=getData called from head.js in the OSM web
   * dashboard.  No API for fetching them is known.
   *
   * @param int $patrolLevel  the code used by OSM to represent a certain role
   *           within a patrol, six, lodge or group.
   * @returns string  the abbreviation for the role.
   */
  public function PatrolLevelName( $patrolLevel ) {
    // See comment in method PatrolLevelAbbr
    $names = array( 'adults'=> [0=>'', 1=>'Assistant Leader',
                                2=>'Section Leader', 3=>'Young Leader',
                                4=>'Section Assistant', 5=>'Occasional Helper',
                                6=>'Group Scout Leader', 7=>'Chair',
                                8=>'Treasurer', 9=>'Secretary',
                                10=>'Waiting List Coordinator',
                                11=>'Quartermaster', 12=>'Fundraising Rep',
                                13=>'Assistant Group Scout Leader',
                                14=>'Parent Rep'],
                    'beavers'=>[0=>'Normal', 1=>'Assistant Lodge Leader',
                                2=>'Lodge Leader',  3=>'Senior Lodge Leader'],
                    'cubs'   =>[0=>'Normal', 1=>'Seconder',
                                2=>'Sixer',         3=>'Senior Sixer'],
                    'scouts' =>[0=>'Normal', 1=>'Assistant Patrol Leader',
                                2=>'Patrol Leader', 3=>'Senior Patrol Leader']
                  );
    return $names[$this->type][ $patrolLevel ] ?? '';
  }
  
  /** Returns level of permission the logged-in user has for the given section.
   *
   * @param string $area  one or more strings specifying areas of the API we may
   *           wish to access.  The permitted strings are as follows: badge
   *           (Qualifications), member (Personal Details), user
   *           (Administration), contact (unknown), register (Attendance),
   *           programme (Programme), events (Events), flexi (Flexi-Records),
   *           finance (Finances) and quartermaster (Quartermaster).
   *
   * @return int the lowest level of permission the current user has granted
   *           this application in the areas specified in the parameters.  I.e.
   *           if the user has granted read permission in one named area and
   *           write permission in another then the result will indicate read
   *           permission.  Values are 0 => No permission, 10 => Read-only,
   *           20 => read and write, 100 => Adminstrator.
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
    $term = $this->TermAt();
    if ($term) {
      $scouts = $term->Scouts();
      return $scouts;
    }
    return array();
  }

  /** Returns all the terms defined for this section.
   *
   * @return Term[int] an array of terms defined for this section, sorted in
   *           descending order of start date.
   */
  public function Terms()
  { if ($this->terms === null) $this->osm->ApiGetTerms();
    if ($this->terms === null) $this->terms = array();
    usort( $this->terms,
           function( $a, $b ) { 
             $r = $a->startDate->getTimeStamp() - $b->startDate->getTimestamp();
             if ($r !== 0) return $r;
             return $b->endDate->getTimeStamp() - $a->endDate->getTimestamp();
           } );
    return $this->terms;
  }

  /** Find the term having a given name
   *
   * @param string $name  the name of the term we are looking for.
   *
   * @returns the latest term having the given name.
   */
  public function FindTermByName( $name ) {
    foreach ($this->Terms() as $term) {
      if ($term->name == $name) return $term;
    }
    return null;
  }
  
  /** Get term (for this section) which covers the given date.
   *
   * @param \DateTime|null $day a date you want to find the term for.
   *
   * @returns OSTTerm|null  a term including the given date, or as close to
   *           doing so as possible.  Null is returned only if there are no
   *           terms defined for this section.
  */
  public function TermAt( $day = null ) {
    $this->Terms();
    if (is_string( $day )) $day = date_create( $day );
    $ts = $day ? $day->getTimestamp() : time();
    foreach ($this->terms as $term) {
      if ($term->startDate->getTimestamp() <= $ts &&
          $term->endDate->getTimestamp() + 86400 > $ts) {
        return $term;
      }
    }

    // If we don't have any terms defined... we just have to return null
    if (count($this->terms) === 0) return null;
    
    // If the specified date is after the latest term, return the latest term
    if ($ts > $this->terms[0]->startDate->getTimestamp())
      return $this->terms[0];
    
    // Otherwise, return the earliest term
    return end( $this->terms );
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
    if ($this->userPermissions === null) $this->osm->ApiGetUserRoles();
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
          throw new \Exception( "$a is not a permission name" );
      }
    }
    return $p;
  }
}

/** A Term for a particular section
 *
 * Many API calls require that a Term be specified to allow results to be
 * limited to those valid at a particular time.  Most obviously, a section's
 * membership will vary depending upon which term you are asking about.
 */
class Term extends BaseObject
{ /** @var bool[]  array, indexed by badge id & version, indicating whether the GetBadgeRecords API
   *            call has been made for the relevant badge.  This call will fetch details of progress
   *            on a given badge for all scouts current in the term.
   */
  private $apiGotBadgeRecords = array();
  
  /** Badge[][]  Array with four elements indexed by Badge::CHALLENGE etc.  Each element, if
   * defined, is an array containing the badges of that type available in the term. */
  private $badges = array();

  /** @var Date  last day of term.  Users are recommended to set this so
   * that no gap is left between the end of one term and the start of the next,
   * but this is not a requirement.  It is also permitted to have overlapping
   * terms.  The value will have a time part of zero, although the term is
   * considered to last until the end of the given day.
   */
  public $endDate;

  /** @var int  Unique Id for the term.  These are globally unique across all
   * sections, although each term relates only to a single section.
   */
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

  /** @var Date  first day of term.  Users are recommended to set this so
   * that no gap is left between the end of one term and the start of the next,
   * but this is not a requirement.  It is also permitted to have overlapping
   * terms.
   */
  public $startDate;
  
  /** Object constructor (should be called only from OSM class)
   *
   * @param Section $section the section to which this term belongs
   * @param \stdClass $apiTerm an object with properties describing the OSM term to be created.  This object
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
  
  /** Magic method to convert object to string (used wherever a Term is used in
   * a context requiring a string).
   * @return string  simply returns the name of the term.
   */
  public function __toString()
  { return $this->name;
  }

  /** Call API endpoint badgesbyperson to get information about all badges held
   * (or being worked on) by every scout active in this term.
   * Note that detailed progress on each requirement is not fetched by this
   * call.
   */
  public function ApiBadgesByPerson() {
    $r = $this->osm->PostAPI( "ext/badges/badgesbyperson/" .
                   "?action=loadBadgesByMember&section={$this->section->type}" .
                        "&sectionid={$this->section->id}&term_id={$this->id}" );
    foreach ($r->data as $apiScout) {
      $scout = $this->section->FindScout( $apiScout->scoutid );
      $scout->ApiUseBadgesByPerson( $apiScout );
    }
  }

  /** Get the list of members active in this section during this term.
   * @TODO Allow for calls with termid=-1 which I believe will return members
   *       from all terms.
   */
  public function ApiGetListOfMembers()
  { if ($this->scouts === null) {
      $this->scouts = array();
      $r = $this->osm->PostAPI( "ext/members/contact/?action=getListOfMembers&sort=lastname" .
                                 "&sectionid={$this->section->id}&termid={$this->id}" .
                                 "&section={$this->section->type}" );
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
      $r = $this->osm->PostAPI( "ext/badges/records/?action=getBadgeStructureByType" .
                                "&section={$this->section->type}&type_id=$type" .
                                "&term_id={$this->id}&section_id={$this->section->id}" );
      $badges = array();
      foreach ($r->details as $idv => $apiItem) {
        $badge = $this->osm->Badge( $idv );
        $apiTasks = $r->structure->$idv;
        $badge->ApiUseGetBadgeStructure( $apiItem, $apiTasks );
        $badges[$idv] = $badge;
      }
      $this->badges[$type] = $badges;
    }
    return $this->badges[$type];
  }

  /** Call API to get details of work towards a particular badge for all scouts
   * in this term.
   *
   * On return, all scouts in this term will have progress towards the given
   * badge available.
   *
   * @param Badge $badge  The badge for which we want progress records.
   */
  public function ApiGetBadgeRecords( $badge ) {
    if (isset($this->apiGotBadgeRecords[$badge->idv])) return;
    $this->apiGotBadgeRecords[$badge->idv] = true;
    $r = $this->osm->PostAPI( "ext/badges/records/?action=getBadgeRecords&term_id={$this->id}" .
                   "&section={$this->section->type}&badge_id={$badge->id}" .
                   "&section_id={$this->section->id}&badge_version={$badge->version}&underscores" );
    foreach ($r->items as $item) {
      $scout = $this->section->FindScout( (int) $item->scoutid );
      $scout->ApiUseGetBadgeRecords( $badge, $item );
    }
  }

  /** Remove references to other objects.
   *
   * Used while clearing the cache of an OSM object to remove anything that may
   * result in a circular reference.  This allows the PHP garbage collecter to
   * be more efficient.
   */
  public function BreakCache() {
    $this->badges = null;
    $this->osm = null;
    if ($this->scouts)
      foreach ($this->scouts as $scout) $scout->BreakCache();
    $this->scouts = null;
    $this->section = null;
  }

  /** Finds a scout, active in this term, with the given name.
   *
   * @param string|null $firstName  if non-empty, the method will search for a
   *           scout with this first name.
   * @param string|null $lastName  if non-empty, the method will search for a
   *           scout with this last name.
   *
   * @return Scout|null  returns a scout, active in this term, whose name
   *           matches the arguments, or null if no such scout can be found.
   *           If there are multiple matches then one of them will be returned.
   */
  public function FindScoutByName( $firstName, $lastName ) {
    foreach ($this->Scouts() as $scout) {
      if (($firstName === null || $scout->firstName == $firstName) &&
          ($lastName === null || $scout->lastName == $lastName))
        return $scout;
    }
    return null;
  }

  /** Is the specified scout a member (of the term's section) during this term?
   *
   * @param int $scoutId  the id of the scout about whom we are enquiring.
   * @return bool  true iff the given scout was a member of this term's section
   *           during this term.
   */
  public function IsMember( int $scoutId )
  { $this->Scouts();
    if (isset($this->scouts[$scoutId])) return $this->scouts[$scoutId];
    return null;
  }

  /** Return all scouts who were members of this term's section during this
   * term.
   * @return Scout[int] array of scouts
   */
  public function Scouts() {
    $this->ApiGetListOfMembers();
    return $this->scouts;
  }

  /** The section for whom this term is defined.
   *
   * Each section has it's own set of terms, and this method tells us for which
   * section this term is defined.
   * @return Section
   */
  public function Section()
  { return $this->section;
  }
}
