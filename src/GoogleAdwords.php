<?php namespace AliasProject\GoogleAdwords;

use Log;
use Google\AdsApi\AdWords\AdWordsServices;
use Google\AdsApi\AdWords\AdWordsSessionBuilder;
use Google\AdsApi\AdWords\v201809\cm\Paging;
use Google\AdsApi\Common\OAuth2TokenBuilder;
use Google\AdsApi\Common\Util\MapEntries;
use Google\AdsApi\Common\AdsLoggerFactory;
use Google\AdsApi\AdWords\v201809\o\TargetingIdeaService;
use Google\AdsApi\AdWords\v201809\o\TargetingIdeaSelector;
use Google\AdsApi\AdWords\v201809\o\RelatedToQuerySearchParameter;
use Google\AdsApi\AdWords\v201809\o\LocationSearchParameter;
use Google\AdsApi\AdWords\v201809\cm\Location;
use Google\AdsApi\AdWords\v201809\cm\NetworkSetting;
use Google\AdsApi\AdWords\v201809\cm\ApiException;
use Google\AdsApi\AdWords\v201809\cm\RateExceededError;
use Google\AdsApi\AdWords\v201809\o\NetworkSearchParameter;
use Google\AdsApi\AdWords\v201809\o\Range;
use Google\AdsApi\AdWords\v201809\cm\LocationCriterionService;
use Google\AdsApi\AdWords\v201809\cm\Predicate;
use Google\AdsApi\AdWords\v201809\cm\PredicateOperator;
use Google\AdsApi\AdWords\v201809\cm\Selector;

class GoogleAdwords
{
    protected $adWordsServices;
    private $session;
    private $manager_id;
    private $client_id;
    private $client_secret;
    private $refresh_token;
    private $developer_token;
    protected $location_types = [
        'City',
        'Postal Code',
        'State',
        'Country'
    ];

    public function __construct()
    {
        // Set API Keys
        $this->manager_id = config('googleadwords.manager_id');
        $this->client_id = config('googleadwords.client_id');
        $this->client_secret = config('googleadwords.client_secret');
        $this->refresh_token = config('googleadwords.refresh_token');
        $this->developer_token = config('googleadwords.developer_token');

        // Create Adwords User
        $adsLoggerFactory = (new AdsLoggerFactory())->createLogger('AW_SOAP', null, 'ERROR');
        $oAuth2Credential = (new OAuth2TokenBuilder())->withClientId($this->client_id)->withClientSecret($this->client_secret)->withRefreshToken($this->refresh_token)->build();
        $this->session = (new AdWordsSessionBuilder())->withDeveloperToken($this->developer_token)->withUserAgent('AliasProject\GoogleAdwords')->withClientCustomerId($this->manager_id)->withOAuth2Credential($oAuth2Credential)->withSoapLogger($adsLoggerFactory)->build();

        $this->adWordsServices = new AdWordsServices();
    }

    public function collectKeywordSearchVolume(array $keywords, bool $competition=false, array $location_codes=[], array $attribute_types=['KEYWORD_TEXT', 'SEARCH_VOLUME', 'AVERAGE_CPC'])
    {
        try {
            // Set Service
            $targetingIdeaService = $this->adWordsServices->get($this->session, TargetingIdeaService::class);

            // Create selector.
            $selector = new TargetingIdeaSelector();
            $selector->setRequestType('STATS');
            $selector->setIdeaType('KEYWORD');

            if ($competition) {
                $attribute_types[] = 'COMPETITION';
            }

            $selector->setRequestedAttributeTypes($attribute_types);

            // Set selector paging (required for targeting idea service).
            $paging = new Paging();
            $paging->setStartIndex(0);
            $paging->setNumberResults(800);
            $selector->setPaging($paging);

            // Create related to keyword search parameter.
            $relatedToKeywordSearchParameter = new RelatedToQuerySearchParameter();
            $relatedToKeywordSearchParameter->setQueries($keywords);

            // Set Network
            $networks = [];
            $networkSetting = new NetworkSetting();
            $networkSetting->setTargetGoogleSearch(true);
            $networkSetting->setTargetSearchNetwork(false);
            $networkSetting->setTargetContentNetwork(false);
            $networkSetting->setTargetPartnerSearchNetwork(false);
            $network = new NetworkSearchParameter();
            $network->setNetworkSetting($networkSetting);

            // Set search parameters
            $search_parameters = [$relatedToKeywordSearchParameter, $network];

            // Build results per location
            if (!empty($location_codes)) {
                $location_arr = [];
                foreach ($location_codes as $location_code) {
                    $location = new Location($location_code);
                    $location_arr[] = $location;
                }

                $locationTargetParameter = new LocationSearchParameter();
                $locationTargetParameter->setLocations($location_arr);

                $search_parameters[] = $locationTargetParameter;
            }

            // Apply search parameters
            $selector->setSearchParameters($search_parameters);

            // Collect results
            $page = $targetingIdeaService->get($selector);
            $entries = $page->getEntries();
            $report = [];

            if ($entries) {
                foreach ($entries as $targetingIdea) {
                    $data = MapEntries::toAssociativeArray($targetingIdea->getData());
                    $keyword = $data['KEYWORD_TEXT']->getValue();

                    if (in_array('SEARCH_VOLUME', $attribute_types)) {
                        $report[$keyword]['search_volume'] = $data['SEARCH_VOLUME']->getValue() ?? 0;
                    }

                    if (in_array('AVERAGE_CPC', $attribute_types)) {
                        $report[$keyword]['average_cpc'] = (isset($data['AVERAGE_CPC']) && $data['AVERAGE_CPC']->getValue()) ? number_format($data['AVERAGE_CPC']->getValue()->getMicroAmount() / 1000000, 2) : 0;
                    }

                    if (isset($data['COMPETITION'])) {
                        $report[$keyword]['competition'] = $data['COMPETITION']->getValue();
                    }

                    if (in_array('TARGETED_MONTHLY_SEARCHES', $attribute_types) && isset($data['TARGETED_MONTHLY_SEARCHES'])) {
                        $range = [];

                        foreach ($data['TARGETED_MONTHLY_SEARCHES']->getValue() as $result) {
                            $range[date('Y-m', strtotime($result->getYear() . '-' . $result->getMonth()))] = $result->getCount();
                        }

                        $report[$keyword]['range'] = $range;
                    }
                }
            } else {
              Log::error('Adwords: No keywords found.');
            }

            return $report;
        } catch (ApiException $apiException) {
            foreach ($apiException->getErrors() as $error) {
                Log::info(json_encode($error));
                if ($error instanceof RateExceededError) {
                    Log::info('Rate Exceeded.');
                    // Sleep
                    Log::info('Retry after ' . $error->getRetryAfterSeconds() * round(1 + 1 * mt_rand(0,32767) / 32767, 4));
                    sleep($error->getRetryAfterSeconds() * round(1 + 1 * mt_rand(0,32767) / 32767, 4));

                    // Retry
                    $this->collectKeywordSearchVolume($keywords, $competition, $location_codes, $attribute_types);
                } else {
                    Log::info(get_class($error));
                }
            }
        }
    }

    /**
     * Lookup Location Code
     *
     * @param array $locations
     * @param string $type - Possible: City, Postal Code, State, Country
     * @param string $locale
     * @return array
     */
    public function getLocationId(array $locations, string $type="City", string $locale="en")
    {
        try {
            // Convert locations to lower case
            $locations = array_map('strtolower', $locations);

            // Get Service
            $locationCriterionService = $this->adWordsServices->get($this->session, LocationCriterionService::class);

            // Create selector.
            $selector = new Selector();

            // Set Fields
            $selector->setFields(['Id', 'LocationName', 'DisplayType', 'TargetingStatus', 'Reach']);

            // Set Predicates
            $selector->setPredicates([
                new Predicate('LocationName', PredicateOperator::IN, $locations),
                new Predicate('Locale', PredicateOperator::EQUALS, [$locale])
            ]);

            // Make Request
            $locationCriteria = $locationCriterionService->get($selector);
            $results = [];

            if ($locationCriteria !== null) {
                foreach ($locationCriteria as $locationCriterion) {
                    // Verify Active Status
                    if ($locationCriterion->getLocation()->getTargetingStatus() !== 'ACTIVE') continue;

                    $location_name = $locationCriterion->getLocation()->getLocationName();
                    $location_id = $locationCriterion->getLocation()->getId();
                    $display_type = $locationCriterion->getLocation()->getDisplayType();
                    $reach = $locationCriterion->getReach();

                    // If city is set, continue
                    if (isset($results[$display_type][$location_name])) continue;

                    // If Display Type is not in avaiable location types, skip.
                    if (!in_array($display_type, $this->location_types)) continue;

                    // Match entered location with proper name
                    foreach ($locations as $location) {
                        $location_word_arr = explode(' ', $location);
                        foreach ($location_word_arr as $location_word) {
                            if (strpos(strtolower($location_name), strtolower($location_word)) !== false) {
                                $results[$display_type][$location_name] = $location_id;

                                // Remove location after it's been set
                                $locations = array_diff($locations, [$location]);

                                break 2;
                            }
                        }
                    }
                }
            }

            return $results;
        } catch (ApiException $apiException) {
            foreach ($apiException->getErrors() as $error) {
                Log::info(json_encode($error));
                if ($error instanceof RateExceededError) {
                    Log::info('Rate Exceeded.');
                    // Sleep
                    Log::info('Retry after ' . $error->getRetryAfterSeconds() * round(1 + 1 * mt_rand(0,32767) / 32767, 4));
                    sleep($error->getRetryAfterSeconds() * round(1 + 1 * mt_rand(0,32767) / 32767, 4));

                    // Retry
                    $this->getLocationId($locations, $type, $locale);
                } else {
                    Log::info(get_class($error));
                }
            }
        }
    }
}
