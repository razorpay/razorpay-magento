<?php

namespace Razorpay\Magento\Controller\OneClick;

/**
 * State name mapping
 */
class StateMap
{
    function getMagentoStateName($country, $stateName)
    {
        switch ($country) {
            case 'in':
                $magentoStateName = $this->getStateNameIN(strtoupper($stateName));
                break;

            default:
                $magentoStateName = $stateName;
                break;
        }
        return $magentoStateName;
    }

    //Fetching the state name on Magento using the state name for India
    function getStateNameIN($stateName)
    {
        $stateCodeMap = [
            'ANDAMAN&NICOBARISLANDS'   => 'Andaman and Nicobar',
            'ANDAMANANDNICOBARISLANDS' => 'Andaman and Nicobar',
            'ANDHRAPRADESH'            => 'Andhra Pradesh',
            'ARUNACHALPRADESH'         => 'Arunachal Pradesh',
            'ASSAM'                    => 'Assam',
            'BIHAR'                    => 'Bihar',
            'CHANDIGARH'               => 'Chandigarh',
            'CHATTISGARH'              => 'Chhattisgarh',
            'CHHATTISGARH'             => 'Chhattisgarh',
            'DADRA&NAGARHAVELI'        => 'Dadra and Nagar Haveli',
            'DADRAANDNAGARHAVELI'      => 'Dadra and Nagar Haveli',
            'DAMAN&DIU'                => 'Daman and Diu',
            'DAMANANDDIU'              => 'Daman and Diu',
            'DELHI'                    => 'Delhi',
            'GOA'                      => 'Goa',
            'GUJARAT'                  => 'Gujarat',
            'HARYANA'                  => 'Haryana',
            'HIMACHALPRADESH'          => 'Himachal Pradesh',
            'JAMMU&KASHMIR'            => 'Jammu and Kashmir',
            'JAMMUANDKASHMIR'          => 'Jammu and Kashmir',
            'JAMMUKASHMIR'             => 'Jammu and Kashmir',
            'JHARKHAND'                => 'Jharkhand',
            'KARNATAKA'                => 'Karnataka',
            'KERALA'                   => 'Kerala',
            'LAKSHADWEEP'              => 'Lakshadweep',
            'LAKSHADEEP'               => 'Lakshadweep',
            'LADAKH'                   => 'Ladakh',
            'MADHYAPRADESH'            => 'Madhya Pradesh',
            'MAHARASHTRA'              => 'Maharashtra',
            'MANIPUR'                  => 'Manipur',
            'MEGHALAYA'                => 'Meghalaya',
            'MIZORAM'                  => 'Mizoram',
            'NAGALAND'                 => 'Nagaland',
            'ODISHA'                   => 'Orissa',
            'PONDICHERRY'              => 'Pondicherry',
            'PUNJAB'                   => 'Punjab',
            'RAJASTHAN'                => 'Rajasthan',
            'SIKKIM'                   => 'Sikkim',
            'TAMILNADU'                => 'Tamil Nadu',
            'TRIPURA'                  => 'Tripura',
            'TELANGANA'                => 'Telangana',
            'UTTARPRADESH'             => 'Uttar Pradesh',
            'UTTARAKHAND'              => 'Uttarakhand',
            'WESTBENGAL'               => 'West Bengal',
        ];

        $trimmedStateName = str_replace(' ', '', $stateName);

        $magentoStateName = $stateCodeMap[$trimmedStateName] ?? $stateName;

        return $magentoStateName;
    }
}
