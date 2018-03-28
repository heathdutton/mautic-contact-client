<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticContactClientBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\CoreBundle\Helper\PhoneNumberHelper;
use Mautic\LeadBundle\Entity\Lead as Contact;

/**
 * Class CacheRepository.
 */
class CacheRepository extends CommonRepository
{
    const MATCHING_ADDRESS  = 16;

    const MATCHING_EMAIL    = 2;

    const MATCHING_EXPLICIT = 1;

    const MATCHING_MOBILE   = 8;

    const MATCHING_PHONE    = 4;

    const SCOPE_CATEGORY    = 2;

    const SCOPE_GLOBAL      = 1;

    const SCOPE_UTM_SOURCE  = 4;

    /** @var PhoneNumberHelper */
    protected $phoneHelper;

    /**
     * Given a matching pattern and a contact, find any exceeded limits (aka caps/budgets).
     *
     * @param ContactClient $contactClient
     * @param array         $rules
     *
     * @return bool|mixed
     *
     * @throws \Exception
     */
    public function findLimit(
        ContactClient $contactClient,
        $rules = []
    ) {
        $filters = [];
        $result  = null;
        foreach ($rules as $rule) {
            $orx      = [];
            $value    = $rule['value'];
            $scope    = $rule['scope'];
            $duration = $rule['duration'];
            $quantity = $rule['quantity'];

            // Scope UTM Source
            if ($scope & self::SCOPE_UTM_SOURCE) {
                $utmSource = trim($value);
                if (!empty($utmSource)) {
                    $orx['utm_source'] = trim($value);
                }
            }

            // Scope Category
            if ($scope & self::SCOPE_CATEGORY) {
                $category = intval($value);
                if ($category) {
                    $orx['category_id'] = $category;
                }
            }

            // Match duration (always, including global scope)
            $oldest = new \DateTime();
            $oldest->sub(new \DateInterval($duration));
            $filters[] = [
                'orx'              => $orx,
                'date_added'       => $oldest->format('Y-m-d H:i:s'),
                'contactclient_id' => $contactClient->getId(),
            ];

            // Run the query to get the count.
            $count = $this->applyFilters($filters, true);
            if ($count > $quantity) {
                // Return the object, plus the count generated by the query.
                $result = [
                    'rule'  => $rule,
                    'count' => $count,
                ];
                break;
            }
        }

        return $result;
    }

    /**
     * @param array $filters
     * @param bool  $returnCount
     *
     * @return mixed|null
     */
    private function applyFilters($filters = [], $returnCount = false)
    {
        $result = null;
        // Convert our filters into a query.
        if ($filters) {
            $alias = $this->getTableAlias();
            $query = $this->getEntityManager()->getConnection()->createQueryBuilder();
            if ($returnCount) {
                $query->select('COUNT(*)');
            } else {
                $query->select('*');
                $query->setMaxResults(1);
            }
            $query->from(MAUTIC_TABLE_PREFIX.'contactclient_cache', $alias);

            foreach ($filters as $k => $set) {
                // Expect orx, anx, or neither.
                if (isset($set['orx'])) {
                    if (count($set['orx'])) {
                        $expr = $query->expr()->orX();
                    }
                    $properties = $set['orx'];
                } elseif (isset($set['andx'])) {
                    if (count($set['andx'])) {
                        $expr = $query->expr()->andX();
                    }
                    $properties = $set['andx'];
                } else {
                    $expr       = $query->expr();
                    $properties = $set;
                }
                if (isset($expr)) {
                    foreach ($properties as $property => $value) {
                        if (is_array($value)) {
                            $expr->add(
                                $query->expr()->in($alias.'.'.$property, $value)
                            );
                        } else {
                            $expr->add(
                                $query->expr()->eq($alias.'.'.$property, ':'.$property.$k)
                            );
                            $query->setParameter($property.$k, $value);
                        }
                    }
                }
                if (isset($set['contactclient_id']) && isset($set['date_added'])) {
                    $query->add(
                        'where',
                        $query->expr()->andX(
                            $query->expr()->eq($alias.'.contactclient_id', ':contactClientId'.$k),
                            $query->expr()->gte($alias.'.date_added', ':dateAdded'.$k),
                            (isset($expr) ? $expr : null)
                        )
                    );
                    $query->setParameter('contactClientId'.$k, $set['contactclient_id']);
                    $query->setParameter('dateAdded'.$k, $set['date_added']);
                } elseif (isset($set['exclusive_expire_date'])) {
                    // Expiration/Exclusions will require an extra outer AND expression.
                    if (!isset($exprOuter)) {
                        $exprOuter  = $query->expr()->orX();
                        $expireDate = $set['exclusive_expire_date'];
                    }
                    if (isset($expr)) {
                        $exprOuter->add(
                            $query->expr()->orX($expr)
                        );
                    }
                }

                // Expiration can always be applied globally.
                if (isset($exprOuter) && isset($expireDate)) {
                    $query->add(
                        'where',
                        $query->expr()->andX(
                            $query->expr()->gte($alias.'.exclusive_expire_date', ':exclusiveExpireDate'),
                            $exprOuter
                        )
                    );
                    $query->setParameter('exclusiveExpireDate', $expireDate);
                }

                $result = $query->execute()->fetch();
                if ($returnCount) {
                    $result = intval(reset($result));
                }
            }
        }

        return $result;
    }

    /**
     * Given a matching pattern and a contact, discern if there is a match in the cache.
     * Used for exclusivity and duplicate checking.
     *
     * @param Contact       $contact
     * @param ContactClient $contactClient
     * @param array         $rules
     * @param string        $utmSource
     *
     * @return mixed|null
     *
     * @throws \Exception
     */
    public function findDuplicate(
        Contact $contact,
        ContactClient $contactClient,
        $rules = [],
        $utmSource = ''
    ) {
        // Generate our filters based on the rules provided.
        $filters = [];
        foreach ($rules as $rule) {
            $orx      = [];
            $matching = $rule['matching'];
            $scope    = $rule['scope'];
            $duration = $rule['duration'];

            // Match explicit
            if ($matching & self::MATCHING_EXPLICIT) {
                $orx['contact_id'] = (int) $contact->getId();
            }

            // Match email
            if ($matching & self::MATCHING_EMAIL) {
                $email = trim($contact->getEmail());
                if ($email) {
                    $orx['email'] = $email;
                }
            }

            // Match phone
            if ($matching & self::MATCHING_PHONE) {
                $phone = $this->phoneValidate($contact->getPhone());
                if (!empty($phone)) {
                    $orx['phone'] = $phone;
                }
            }

            // Match mobile
            if ($matching & self::MATCHING_MOBILE) {
                $mobile = $this->phoneValidate($contact->getMobile());
                if (!empty($mobile)) {
                    $orx['mobile'] = $mobile;
                }
            }

            // Match address
            if ($matching & self::MATCHING_ADDRESS) {
                $address1 = trim(ucwords($contact->getAddress1()));
                if (!empty($address1)) {
                    $city    = trim(ucwords($contact->getCity()));
                    $zipcode = trim(ucwords($contact->getZipcode()));

                    // Only support this level of matching if we have enough for a valid address.
                    if (!empty($zipcode) || !empty($city)) {
                        $orx['address1'] = $address1;

                        $address2 = trim(ucwords($contact->getAddress2()));
                        if (!empty($address2)) {
                            $orx['address2'] = $address2;
                        }

                        if (!empty($city)) {
                            $orx['city'] = $city;
                        }

                        $state = trim(ucwords($contact->getState()));
                        if (!empty($state)) {
                            $orx['state'] = $state;
                        }

                        if (!empty($zipcode)) {
                            $orx['zipcode'] = $zipcode;
                        }

                        $country = trim(ucwords($contact->getCountry()));
                        if (!empty($country)) {
                            $orx['country'] = $country;
                        }
                    }
                }
            }

            // Scope UTM Source
            if ($scope & self::SCOPE_UTM_SOURCE) {
                if (!empty($utmSource)) {
                    $orx['utm_source'] = $utmSource;
                }
            }

            // Scope Category
            if ($scope & self::SCOPE_CATEGORY) {
                $category = $contactClient->getCategory();
                if ($category) {
                    $category = $category->getId();
                    if (!empty($category)) {
                        $orx['category_id'] = $category;
                    }
                }
            }

            if ($orx) {
                // Match duration (always), once all other aspects of the query are ready.
                $oldest = new \DateTime();
                $oldest->sub(new \DateInterval($duration));
                $filters[] = [
                    'orx'              => $orx,
                    'date_added'       => $oldest->format('Y-m-d H:i:s'),
                    'contactclient_id' => $contactClient->getId(),
                ];
            }
        }

        return $this->applyFilters($filters);
    }

    /**
     * @param $phone
     *
     * @return string
     *
     * @todo - dedupe this method.
     */
    private function phoneValidate(
        $phone
    ) {
        $result = null;
        $phone  = trim($phone);
        if (!empty($phone)) {
            if (!$this->phoneHelper) {
                $this->phoneHelper = new PhoneNumberHelper();
            }
            try {
                $phone = $this->phoneHelper->format($phone);
                if (!empty($phone)) {
                    $result = $phone;
                }
            } catch (\Exception $e) {
            }
        }

        return $result;
    }

    /**
     * Check the entire cache for matching contacts given all possible Exclusivity rules.
     *
     * Only the first 4 matching rules are allowed for exclusivity (by default).
     * Only the first two scopes are allowed for exclusivity.
     *
     * @param Contact                                                      $contact
     * @param \MauticPlugin\MauticContactClientBundle\Entity\ContactClient $contactClient
     * @param int                                                          $matching
     *
     * @return mixed|null
     */
    public function findExclusive(
        Contact $contact,
        ContactClient $contactClient,
        $matching = self::MATCHING_EXPLICIT | self::MATCHING_EMAIL | self::MATCHING_PHONE | self::MATCHING_MOBILE
    ) {
        // Generate our filters based on all rules possibly in play.
        $filters = [];

        // Match explicit
        if ($matching & self::MATCHING_EXPLICIT) {
            $filters[] = [
                'andx' => [
                    'contact_id'        => (int) $contact->getId(),
                    'exclusive_pattern' => $this->bitwiseIn($matching, self::MATCHING_EXPLICIT),
                ],
            ];
        }

        // Match email
        if ($matching & self::MATCHING_EMAIL) {
            $email = trim($contact->getEmail());
            if ($email) {
                $filters[] = [
                    'andx' => [
                        'email'             => $email,
                        'exclusive_pattern' => $this->bitwiseIn($matching, self::MATCHING_EMAIL),
                    ],
                ];
            }
        }

        // Match phone
        if ($matching & self::MATCHING_PHONE) {
            $phone = $this->phoneValidate($contact->getPhone());
            if (!empty($phone)) {
                $filters[] = [
                    'andx' => [
                        'phone'             => $phone,
                        'exclusive_pattern' => $this->bitwiseIn($matching, self::MATCHING_MOBILE),
                    ],
                ];
            }
        }

        // Match mobile
        if ($matching & self::MATCHING_MOBILE) {
            $mobile = $this->phoneValidate($contact->getMobile());
            if (!empty($mobile)) {
                $filters[] = [
                    'andx' => [
                        'phone'             => $mobile,
                        'exclusive_pattern' => $this->bitwiseIn($matching, self::MATCHING_PHONE),
                    ],
                ];
            }
        }

        // Due to high overhead, we've left out address-based exclusivity for now.
        // Match address
        if ($matching & self::MATCHING_ADDRESS) {
            $address1 = trim(ucwords($contact->getAddress1()));
            if (!empty($address1)) {
                $filter  = [];
                $city    = trim(ucwords($contact->getCity()));
                $zipcode = trim(ucwords($contact->getZipcode()));

                // Only support this level of matching if we have enough for a valid address.
                if (!empty($zipcode) || !empty($city)) {
                    $filter['address1'] = $address1;

                    $address2 = trim(ucwords($contact->getAddress2()));
                    if (!empty($address2)) {
                        $filter['address2'] = $address2;
                    }

                    if (!empty($city)) {
                        $filter['city'] = $city;
                    }

                    $state = trim(ucwords($contact->getState()));
                    if (!empty($state)) {
                        $filter['state'] = $state;
                    }

                    if (!empty($zipcode)) {
                        $filter['zipcode'] = $zipcode;
                    }

                    $country = trim(ucwords($contact->getCountry()));
                    if (!empty($country)) {
                        $filter['country'] = $country;
                    }

                    $filter['exclusive_pattern'] = $this->bitwiseIn($matching, self::MATCHING_ADDRESS);
                    $filters[]                   = [
                        'andx' => $filter,
                    ];
                }
            }
        }

        // Scope Global
        $scopePattern = $this->bitwiseIn($matching, self::SCOPE_GLOBAL);
        foreach ($filters as &$filter) {
            $filter['andx']['exclusive_scope'] = $scopePattern;
        }

        // Scope Category
        $category = $contactClient->getCategory();
        if ($category) {
            $category = $category->getId();
            if ($category) {
                $newFilters   = [];
                $scopePattern = $this->bitwiseIn($matching, self::SCOPE_CATEGORY);
                foreach ($filters as $filter) {
                    $filter['andx']['category_id']     = $category;
                    $filter['andx']['exclusive_scope'] = $scopePattern;
                    $newFilters[]                      = $filter;
                }
                $filters = array_merge($filters, $newFilters);
            }
        }

        $this->addExpiration($filters);

        return $this->applyFilters($filters);
    }

    /**
     * Given bitwise operators, and the value we want to match against,
     * generate a minimal array for an IN query.
     *
     * @param int $max
     * @param     $matching
     *
     * @return array
     */
    private function bitwiseIn(
        $max,
        $matching
    ) {
        $result = [];
        for ($i = 1; $i <= $max; ++$i) {
            if ($i & $matching) {
                $result[] = $i;
            }
        }

        return $result;
    }

    /**
     * Add Exclusion Expiration date.
     *
     * @param array $filters
     */
    private function addExpiration(
        &$filters = []
    ) {
        if ($filters) {
            $expiration = new \DateTime();
            $expiration = $expiration->format('Y-m-d H:i:s');
            foreach ($filters as &$filter) {
                $filter['exclusive_expire_date'] = $expiration;
            }
        }
    }
}
