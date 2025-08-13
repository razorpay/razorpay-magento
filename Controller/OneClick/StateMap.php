<?php

namespace Razorpay\Magento\Controller\OneClick;

use Razorpay\Magento\Model\TrackPluginInstrumentation;
/**
 * State name mapping
 */
class StateMap
{
    protected $trackPluginInstrumentation;

    public function __construct(
        TrackPluginInstrumentation $trackPluginInstrumentation
    ) {
        $this->trackPluginInstrumentation = $trackPluginInstrumentation;
    }

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
            'ODISHA'                   => 'Odisha',
            'PONDICHERRY'              => 'Puducherry',
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

        //if state name is not in the map, then want to add a alert in the track plugin instrumentation
        if (!isset($stateCodeMap[$trimmedStateName])) {
            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.state.map.failed', [
                'error_message' => 'state name not in the map for state name: ' . $stateName,
                'file_path' => 'controller/OneClick/StateMap.php',
                'exception_type' => null,
                'notes' => 'state name not found in the mapping file'
            ]);
        }

        $magentoStateName = $stateCodeMap[$trimmedStateName] ?? $stateName;

        return $magentoStateName;
    }
}
