<?php
namespace Drupal\scitalk_base\ScitalkServices;

use Drupal\Core\Entity\EntityInterface;
use GuzzleHttp\Exception\GuzzleException;  
use GuzzleHttp\Exception\ConnectException;  
use GuzzleHttp\Exception\ClientException;  
use GuzzleHttp\Exception\ServerException;  
use GuzzleHttp\Exception\BadResponseException;  
use GuzzleHttp\Exception\RequestException;  

class DataCiteDOI {
  
  private const DOI_STATE_TO_FINDABLE = 'publish';
  private const DOI_STATE_FROM_FINDABLE_TO_REGISTER = 'hide';
  private const DOI_STATE_FROM_DRAFT_TO_REGISTER = 'register';

  private $doi_prefix;
  private $datacite_user;
  private $datacite_pwd;
  private $datacite_creator_institution;
  private $datacite_creator_institution_ror;

  public function __construct() {
    $config = \Drupal::config('scitalk_base.settings');
    $this->doi_prefix = $config->get('doi_prefix');
    $this->datacite_user = $config->get('datacite_user');
    $this->datacite_pwd = $config->get('datacite_pwd');
    $this->datacite_creator_institution = $config->get('datacite_creator_institution');
    $this->datacite_creator_institution_ror = !empty($config->get('datacite_creator_institution_ror')) ? 'https://ror.org/' . $config->get('datacite_creator_institution_ror') : '';
  }

  //create DOI Draft
  public function create( EntityInterface $entity) {
     $doiObj = $this->buildDOIObject($entity);
    return $this->createDOI($doiObj);
  }

  //Update DOI to either "Registed" or "FIndable" based on media status in the Talk
  public function update( EntityInterface $entity) {
   // \Drupal::logger('scitalk_base')->notice('<pre><code>go and REGISTER/FINDABLE a DOI for this entity ' . print_r($entity->toArray() , TRUE) . '</code></pre>');
    //$this->getPIDOIs();

    $doiObj = $this->buildDOIObject($entity);
    return $this->updateDOI($doiObj);
  }

  private function createDOI($doiObj) {
    $url = "https://api.test.datacite.org/dois";
    $client = \Drupal::httpClient();

    $doi_id = '';
    $params = [
      'auth' => [$this->datacite_user,$this->datacite_pwd], 
      'json' => $doiObj
    ];

    try {
     // $request = $client->post($url, $auth, json_encode($doiObj));//['body' => json_encode($doiObj)]);
      $request = $client->post($url, $params);
      $response = $request->getBody();
      $response = json_decode($response);
      $doi_id = $response->data->id;

      \Drupal::logger('scitalk_base')->notice('<pre><code>DOI CREATED! ' . print_r($response , TRUE) . '</code></pre>');
    }
    catch (ClientException | RequestException | ConnectException | GuzzleException | BadResponseException | ServerException $e) {
      if (!empty($res = $e->getResponse()->getBody()->getContents())) {
        $err = json_decode($res);
        $msg = 'DOI Client error ' . ( $err->errors[0]->title ?? '');
        drupal_set_message(t($msg), 'error');
      }
      
      \Drupal::logger('scitalk_base')->error('<pre>ERROR CONNECTING to DOI ' . print_r($e->getMessage() , TRUE) .'</pre>');
    }
    finally {
      return $doi_id;
    }
  }

  private function updateDOI($doiObj) {
    $doi_id = $doiObj['data']['id'];
    $url = "https://api.test.datacite.org/dois/" . $doi_id;
    //\Drupal::logger('scitalk_base')->notice('<pre><code>GO AND UPDATE! ' .print_r($doiObj,true). print_r(json_encode($doiObj) , TRUE) . '</code></pre>');
    $client = \Drupal::httpClient();

    $params = [
      'auth' => [$this->datacite_user,$this->datacite_pwd],
      'json' => $doiObj
    ];

    try {
      $request = $client->put($url, $params);
      $response = $request->getBody();
      $response = json_decode($response);
      $doi_id = $response->data->id;

      \Drupal::logger('scitalk_base')->notice('<pre><code>DOI UPDATED! ' .$doi_id . print_r($response , TRUE) . '</code></pre>');
    }
    catch (ClientException | RequestException | ConnectException | GuzzleException | BadResponseException | ServerException $e) {
      if (!empty($res = $e->getResponse()->getBody()->getContents())) {
        $err = json_decode($res);
        $msg = 'DOI Client error ' . ( $err->errors[0]->title ?? '');
        drupal_set_message(t($msg), 'error');
      }
      
      \Drupal::logger('scitalk_base')->error('<pre>ERROR CONNECTING to DOI ' . print_r($e->getMessage() , TRUE) .'</pre>');
    }
    finally {
      return $doi_id;
    }

  }


  private function getPIDOIs() {
    // $base_uri = "https://api.test.datacite.org/dois";
    // $params = [
    //   'auth' => ['PITP.PIRSA','pirsa-uat'],
    //   'query' => ['client-id', 'pitp.pirsa'] 
    // ];
    // $client = \Drupal::httpClient(['base_uri' => $base_uri]);

    $url = "https://api.test.datacite.org/dois?client-id=pitp.pirsa";
    $auth = ['auth' => [$this->datacite_user,$this->datacite_pwd]];

    $client = \Drupal::httpClient();

    try {
      $request = $client->get($url, $auth);
      $response = $request->getBody();
      \Drupal::logger('scitalk_base')->notice('<pre><code>get list of DOIS ' . print_r(json_decode($response) , TRUE) . '</code></pre>');
    }
    catch (RequestException $e) {
      \Drupal::logger('scitalk_base')->notice('<pre><code>ERROR geting list of DOIS ' . print_r($e->getMessage() , TRUE) . '</code></pre>');
    }
    
  }

  private function buildDOIObject($entityObj) {
    $entity = $entityObj->getTypedData();

    $talk_number = $entity->get('field_talk_number')->getValue();
    $abstract = $entity->get('field_talk_abstract')->getValue();
    $talk_id = $this->doi_prefix . '/' . $entity->get('field_talk_number')->value;
    
    //$reference_number = $entity->field_talk_number->value; 
    $data = [
      'id' => $talk_id,
      'type' => 'dois',
      'attributes' => [
        'doi' => $talk_id,
        'publisher' => 'Perimeter Institute',
        'titles' => [
          'title' => $entity->get('title')->value ?? ''
        ],
        'descriptions' => [
          'description' => strip_tags( $entity->get('field_talk_abstract')->value ?? '')
        ],
        'types' => [
          'resourceTypeGeneral' => "Text"
        ],
        'url' => 'https://schema.datacite.org/meta/kernel-4.0/index.html',
        'schemaVersion' => 'http://datacite.org/schema/kernel-4'
      ]
    ];

    //speaker info (use the institution "Perimeter Institute" instead of the talk speaker)
    //$speakerProfile = $this->getSpeakerInfo($entity->get('field_talk_speaker_profile')->getValue()); //target_id);
    $speakerProfile =  $this->getCreator();
    if (!empty($speakerProfile)) {
      $data['attributes']['creators'] = $speakerProfile;
    }

    //publication date info
    $pubDate = $entity->get('field_talk_date')->value ?? '';
    if (!empty($pubDate)) {
      $pubYear = date('Y', strtotime($pubDate));
      $data['attributes']['publicationYear'] = $pubYear;
      $data['attributes']['dates'] = [
        'date' => $pubDate,
        'dateType' => 'Created'
      ];
    }

    /*
      check if media available in the talk and if so then set the DOI status to Findable or Registered
        e.g.   event="register" / event="publish"  (maybe isActive=true/false)
     Possible actions when publishing to findable:
        publish - Triggers a state move from draft or registered to findable
        register - Triggers a state move from draft to registered
        hide - Triggers a state move from findable to registered
    */
    if (!empty($entity->get('field_talk_video')->target_id)) {
      $media = \Drupal::entityTypeManager()->getStorage('media')->load( $entity->get('field_talk_video')->target_id);
      \Drupal::logger('scitalk_base')->notice('<pre><code>TALK VIDEO set ' . print_r($entity->get('field_talk_video')->target_id,true) . print_r($media, TRUE)  .'</code></pre>');

      $data['attributes']['event'] = self::DOI_STATE_TO_FINDABLE; 
    }

    \Drupal::logger('scitalk_base')->notice('<pre><code>generated DOI Object ' . print_r($data, TRUE)  .'</code></pre>');
        
    return ['data' => $data];
  }

   //we are going to use the institution for the Creator field in DOI instead of the talk speakers:
    private function getCreator() {
      
      $speakers[] = [
        'name' => 'Perimeter Institute',
        'nameType' => 'Organizational',
        'affiliation' => [ 
          [
            'name' => $this->datacite_creator_institution,
            'schemeUri' => 'https://ror.org',
            'affiliationIdentifier' => $this->datacite_creator_institution_ror,
            'affiliationIdentifierScheme' => 'ROR'
          ] 
        ]
      ];
      return $speakers;
  }

  private function getSpeakerInfo($speakersObj) {
    //if no speaker return the institution
    if (empty($speakersObj)) {
      return $this->getCreator();  
    }

    $speakers = [];
    foreach ($speakersObj as $sp) {
      $tid = $sp['target_id'];
      $speakerProfile = \Drupal::entityTypeManager()->getStorage('node')->load($tid);
      $speakers[] = [
        'nameType' => 'Personal',
        'givenName' => $speakerProfile->field_sp_first_name->value ?? '',
        'familyName' => $speakerProfile->field_sp_last_name->value ?? 'unknown',
        'affiliation' => [ ['name' => $speakerProfile->field_sp_institution_name->value ?? ''] ]
      ];
    }
    return $speakers;
  }

 
}