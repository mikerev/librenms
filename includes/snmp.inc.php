<?php
/*
 * LibreNMS - SNMP Functions
 *
 * Original Observium code by: Adam Armstrong, Tom Laermans
 * Copyright (c) 2010-2012 Adam Armstrong.
 *
 * Additions for LibreNMS by Paul Gear
 * Copyright (c) 2014-2015 Gear Consulting Pty Ltd <http://libertysys.com.au/>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.  Please see LICENSE.txt at the top level of
 * the source code distribution for details.
 */

function string_to_oid($string)
{
    $oid = strlen($string);
    for ($i = 0; $i != strlen($string); $i++) {
        $oid .= '.'.ord($string[$i]);
    }

    return $oid;
}//end string_to_oid()


function prep_snmp_setting($device, $setting)
{
    global $config;

    if (isset($device[$setting]) && is_numeric($device[$setting]) && $device[$setting] > 0) {
        return $device[$setting];
    } elseif (isset($config['snmp'][$setting])) {
        return $config['snmp'][$setting];
    }
}//end prep_snmp_setting()

/**
 * @param $device
 * @return array $extra will contain a list of mib dirs
 */
function get_mib_dir($device)
{
    global $config;
    $extra = array();

    if (file_exists($config['mib_dir'] . '/' . $device['os'])) {
        $extra[] = $config['mib_dir'] . '/' . $device['os'];
    }

    if (isset($device['os_group']) && file_exists($config['mib_dir'] . '/' . $device['os_group'])) {
        $extra[] = $config['mib_dir'] . '/' . $device['os_group'];
    }

    if (isset($config['os_groups'][$device['os_group']]['mib_dir'])) {
        if (is_array($config['os_groups'][$device['os_group']]['mib_dir'])) {
            foreach ($config['os_groups'][$device['os_group']]['mib_dir'] as $k => $dir) {
                $extra[] = $config['mib_dir'] . '/' . $dir;
            }
        }
    }

    if (isset($config['os'][$device['os']]['mib_dir'])) {
        if (is_array($config['os'][$device['os']]['mib_dir'])) {
            foreach ($config['os'][$device['os']]['mib_dir'] as $k => $dir) {
                $extra[] = $config['mib_dir'] . '/' . $dir;
            }
        }
    }
    
    return $extra;
}

/**
 * Generate the mib search directory argument for snmpcmd
 * If null return the default mib dir
 * If $mibdir is empty '', return an empty string
 *
 * @param string $mibdir should be the name of the directory within $config['mib_dir']
 * @param string $device
 * @return string The option string starting with -M
 */
function mibdir($mibdir = null, $device = array())
{
    global $config;

    $extra_dir = implode(':', get_mib_dir($device));
    if (!empty($extra_dir)) {
        $extra_dir = ":".$extra_dir;
    }

    if (is_null($mibdir)) {
        return " -M ${config['mib_dir']}$extra_dir";
    }

    if (empty($mibdir)) {
        // use system mibs
        return '';
    }

    return " -M ${config['mib_dir']}$extra_dir:${config['mib_dir']}/$mibdir";
}//end mibdir()

/**
 * Generate an snmpget command
 *
 * @param array $device the we will be connecting to
 * @param string $oids the oids to fetch, separated by spaces
 * @param string $options extra snmp command options, usually this is output options
 * @param string $mib an additional mib to add to this command
 * @param string $mibdir a mib directory to search for mibs, usually prepended with +
 * @return string the fully assembled command, ready to run
 */
function gen_snmpget_cmd($device, $oids, $options = null, $mib = null, $mibdir = null)
{
    global $config;
    $snmpcmd  = $config['snmpget'];
    return gen_snmp_cmd($snmpcmd, $device, $oids, $options, $mib, $mibdir);
} // end gen_snmpget_cmd()

/**
 * Generate an snmpwalk command
 *
 * @param array $device the we will be connecting to
 * @param string $oids the oids to fetch, separated by spaces
 * @param string $options extra snmp command options, usually this is output options
 * @param string $mib an additional mib to add to this command
 * @param string $mibdir a mib directory to search for mibs, usually prepended with +
 * @return string the fully assembled command, ready to run
 */
function gen_snmpwalk_cmd($device, $oids, $options = null, $mib = null, $mibdir = null)
{
    global $config;
    if ($device['snmpver'] == 'v1' || (isset($device['os'], $config['os'][$device['os']]['nobulk']) && $config['os'][$device['os']]['nobulk'])) {
        $snmpcmd = $config['snmpwalk'];
    } else {
        $snmpcmd = $config['snmpbulkwalk'];
        $max_repeaters = get_device_max_repeaters($device);
        if ($max_repeaters > 0) {
            $snmpcmd .= " -Cr$max_repeaters ";
        }
    }
    return gen_snmp_cmd($snmpcmd, $device, $oids, $options, $mib, $mibdir);
} //end gen_snmpwalk_cmd()

/**
 * Generate an snmp command
 *
 * @param string $cmd the snmp command to run, like snmpget
 * @param array $device the we will be connecting to
 * @param string $oids the oids to fetch, separated by spaces
 * @param string $options extra snmp command options, usually this is output options
 * @param string $mib an additional mib to add to this command
 * @param string $mibdir a mib directory to search for mibs, usually prepended with +
 * @return string the fully assembled command, ready to run
 */
function gen_snmp_cmd($cmd, $device, $oids, $options = null, $mib = null, $mibdir = null)
{
    global $debug;

    // populate timeout & retries values from configuration
    $timeout = prep_snmp_setting($device, 'timeout');
    $retries = prep_snmp_setting($device, 'retries');

    if (!isset($device['transport'])) {
        $device['transport'] = 'udp';
    }

    $cmd .= snmp_gen_auth($device);
    $cmd .= " $options";
    $cmd .= $mib ? " -m $mib" : '';
    $cmd .= mibdir($mibdir, $device);
    $cmd .= isset($timeout) ? " -t $timeout" : '';
    $cmd .= isset($retries) ? " -r $retries" : '';
    $cmd .= ' '.$device['transport'].':'.$device['hostname'].':'.$device['port'];
    $cmd .= " $oids";

    if (!$debug) {
        $cmd .= ' 2>/dev/null';
    }

    return $cmd;
} // end gen_snmp_cmd()

function snmp_get_multi($device, $oids, $options = '-OQUs', $mib = null, $mibdir = null)
{
    $time_start = microtime(true);

    if (is_array($oids)) {
        $oids = implode(' ', $oids);
    }

    $cmd = gen_snmpget_cmd($device, $oids, $options, $mib, $mibdir);
    $data = trim(external_exec($cmd));


    $array = array();
    foreach (explode("\n", $data) as $entry) {
        list($oid,$value)  = explode('=', $entry, 2);
        $oid               = trim($oid);
        $value             = trim($value);
        list($oid, $index) = explode('.', $oid, 2);
        if (!strstr($value, 'at this OID') && isset($oid) && isset($index)) {
            $array[$index][$oid] = $value;
        }
    }

    recordSnmpStatistic('snmpget', $time_start);
    return $array;
}//end snmp_get_multi()

function snmp_get_multi_oid($device, $oids, $options = '-OUQn', $mib = null, $mibdir = null)
{
    $time_start = microtime(true);

    if (is_array($oids)) {
        $oids = implode(' ', $oids);
    }

    $cmd = gen_snmpget_cmd($device, $oids, $options, $mib, $mibdir);
    $data = trim(external_exec($cmd));

    $array = array();
    foreach (explode("\n", $data) as $entry) {
        list($oid,$value)  = explode('=', $entry, 2);
        $oid               = trim($oid);
        $value             = trim($value);
        if (!strstr($value, 'at this OID') && isset($oid)) {
            $array[$oid] = $value;
        }
    }

    recordSnmpStatistic('snmpget', $time_start);
    return $array;
}//end snmp_get_multi_oid()

function snmp_get($device, $oid, $options = null, $mib = null, $mibdir = null)
{
    $time_start = microtime(true);

    if (strstr($oid, ' ')) {
        echo report_this_text("snmp_get called for multiple OIDs: $oid");
    }

    $cmd = gen_snmpget_cmd($device, $oid, $options, $mib, $mibdir);
    $data = trim(external_exec($cmd));

    recordSnmpStatistic('snmpget', $time_start);
    if (is_string($data) && (preg_match('/(No Such Instance|No Such Object|No more variables left|Authentication failure)/i', $data))) {
        return false;
    } elseif ($data || $data === '0') {
        return $data;
    } else {
        return false;
    }
}//end snmp_get()


function snmp_walk($device, $oid, $options = null, $mib = null, $mibdir = null)
{
    $time_start = microtime(true);

    $cmd = gen_snmpwalk_cmd($device, $oid, $options, $mib, $mibdir);
    $data = trim(external_exec($cmd));

    $data = str_replace('"', '', $data);
    $data = str_replace('End of MIB', '', $data);

    if (is_string($data) && (preg_match('/No Such (Object|Instance)/i', $data))) {
        $data = false;
    } else {
        if (preg_match('/No more variables left in this MIB View \(It is past the end of the MIB tree\)$/', $data)) {
            // Bit ugly :-(
            $d_ex = explode("\n", $data);
            unset($d_ex[(count($d_ex) - 1)]);
            $data = implode("\n", $d_ex);
        }
    }

    recordSnmpStatistic('snmpwalk', $time_start);
    return $data;
}//end snmp_walk()


function snmpwalk_cache_cip($device, $oid, $array = array(), $mib = 0)
{
    $cmd = gen_snmpwalk_cmd($device, $oid, ' -OsnQ', $mib);
    $data      = trim(external_exec($cmd));

    // echo("Caching: $oid\n");
    foreach (explode("\n", $data) as $entry) {
        list ($this_oid, $this_value) = preg_split('/=/', $entry);
        $this_oid   = trim($this_oid);
        $this_value = trim($this_value);
        $this_oid   = substr($this_oid, 30);
        list($ifIndex, $dir, $a, $b, $c, $d, $e, $f) = explode('.', $this_oid);
        $h_a = zeropad(dechex($a));
        $h_b = zeropad(dechex($b));
        $h_c = zeropad(dechex($c));
        $h_d = zeropad(dechex($d));
        $h_e = zeropad(dechex($e));
        $h_f = zeropad(dechex($f));
        $mac = "$h_a$h_b$h_c$h_d$h_e$h_f";

        if ($dir == '1') {
            $dir = 'input';
        } elseif ($dir == '2') {
            $dir = 'output';
        }

        if ($mac && $dir) {
            $array[$ifIndex][$mac][$oid][$dir] = $this_value;
        }
    }//end foreach

    return $array;
}//end snmpwalk_cache_cip()


function snmp_cache_ifIndex($device)
{
    // FIXME: this is not yet using our own snmp_*
    $cmd = gen_snmpwalk_cmd($device, 'ifIndex', ' -OQs', 'IF-MIB');
    $data      = trim(external_exec($cmd));

    $array = array();
    foreach (explode("\n", $data) as $entry) {
        list ($this_oid, $this_value) = preg_split('/=/', $entry);
        list ($this_oid, $this_index) = explode('.', $this_oid, 2);
        $this_index                   = trim($this_index);
        $this_oid   = trim($this_oid);
        $this_value = trim($this_value);
        if (!strstr($this_value, 'at this OID') && $this_index) {
            $array[] = $this_value;
        }
    }

    return $array;
}//end snmp_cache_ifIndex()


function snmpwalk_cache_oid($device, $oid, $array, $mib = null, $mibdir = null, $snmpflags = '-OQUs')
{
    $data = snmp_walk($device, $oid, $snmpflags, $mib, $mibdir);
    foreach (explode("\n", $data) as $entry) {
        list($oid,$value)  = explode('=', $entry, 2);
        $oid               = trim($oid);
        $value             = trim($value);
        list($oid, $index) = explode('.', $oid, 2);
        if (!strstr($value, 'at this OID') && isset($oid) && isset($index)) {
            $array[$index][$oid] = $value;
        }
    }

    return $array;
}//end snmpwalk_cache_oid()

function snmpwalk_cache_long_oid($device, $oid, $noid, $array, $mib = null, $mibdir = null, $snmpflags = '-OQnU')
{
    $data = snmp_walk($device, $oid, $snmpflags, $mib, $mibdir);
    foreach (explode("\n", $data) as $entry) {
        list($tmp_oid,$value)  = explode('=', $entry, 2);
        $tmp_oid               = trim($tmp_oid);
        $value                 = trim($value);
        $tmp_index                 = str_replace($noid, '', $tmp_oid);
        $index                 = md5($tmp_index);
        if (!empty($index) && !empty($oid)) {
            $array[$index][$oid] = $value;
            if (empty($array[$index]['orig'])) {
                $array[$index]['orig'] = $tmp_index;
            }
        }
    }

    return $array;
}//end snmpwalk_cache_oid()

/**
 * Just like snmpwalk_cache_oid except that it returns the numerical oid as the index
 * this is useful when the oid is indexed by the mac address and snmpwalk would
 * return periods (.) for non-printable numbers, thus making many different indexes appear
 * to be the same.
 *
 * @param array $device
 * @param string $oid
 * @param array $array Pass an array to add the cache to, useful for multiple calls
 * @param string $mib
 * @param string $mibdir
 * @return boolean|array
 */
function snmpwalk_cache_oid_num($device, $oid, $array, $mib = null, $mibdir = null)
{
    return snmpwalk_cache_oid($device, $oid, $array, $mib, $mibdir, $snmpflags = '-OQUn');
}//end snmpwalk_cache_oid_num()


function snmpwalk_cache_multi_oid($device, $oid, $array, $mib = null, $mibdir = null, $snmpflags = '-OQUs')
{
    global $cache;

    if (!(is_array($cache['snmp'][$device['device_id']]) && array_key_exists($oid, $cache['snmp'][$device['device_id']]))) {
        $data = snmp_walk($device, $oid, $snmpflags, $mib, $mibdir);
        foreach (explode("\n", $data) as $entry) {
            list($r_oid,$value) = explode('=', $entry, 2);
            $r_oid              = trim($r_oid);
            $value              = trim($value);
            $oid_parts          = explode('.', $r_oid);
            $r_oid              = array_shift($oid_parts);
            $index              = array_shift($oid_parts);
            foreach ($oid_parts as $tmp_oid) {
                $index .= '.'.$tmp_oid;
            }

            if (!strstr($value, 'at this OID') && isset($r_oid) && isset($index)) {
                $array[$index][$r_oid] = $value;
            }
        }//end foreach

        $cache['snmp'][$device['device_id']][$oid] = $array;
    }//end if

    return $cache['snmp'][$device['device_id']][$oid];
}//end snmpwalk_cache_multi_oid()


function snmpwalk_cache_double_oid($device, $oid, $array, $mib = null, $mibdir = null)
{
    $data = snmp_walk($device, $oid, '-OQUs', $mib, $mibdir);

    foreach (explode("\n", $data) as $entry) {
        list($oid,$value) = explode('=', $entry, 2);
        $oid              = trim($oid);
        $value            = trim($value);
        list($oid, $first, $second) = explode('.', $oid);
        if (!strstr($value, 'at this OID') && isset($oid) && isset($first) && isset($second)) {
            $double               = $first.'.'.$second;
            $array[$double][$oid] = $value;
        }
    }

    return $array;
}//end snmpwalk_cache_double_oid()

function snmpwalk_cache_index($device, $oid, $array, $mib = null, $mibdir = null)
{
    $data = snmp_walk($device, $oid, '-OQUs', $mib, $mibdir);

    foreach (explode("\n", $data) as $entry) {
        list($oid,$value) = explode('=', $entry, 2);
        $oid              = trim($oid);
        $value            = trim($value);
        list($oid, $first) = explode('.', $oid);
        if (!strstr($value, 'at this OID') && isset($oid) && isset($first)) {
            $array[$oid][$first] = $value;
        }
    }

    return $array;
}//end snmpwalk_cache_double_oid()

function snmpwalk_cache_triple_oid($device, $oid, $array, $mib = null, $mibdir = null)
{
    $data = snmp_walk($device, $oid, '-OQUs', $mib, $mibdir);

    foreach (explode("\n", $data) as $entry) {
        list($oid,$value) = explode('=', $entry, 2);
        $oid              = trim($oid);
        $value            = trim($value);
        list($oid, $first, $second, $third) = explode('.', $oid);
        if (!strstr($value, 'at this OID') && isset($oid) && isset($first) && isset($second)) {
            $index               = $first.'.'.$second.'.'.$third;
            $array[$index][$oid] = $value;
        }
    }

    return $array;
}//end snmpwalk_cache_triple_oid()


function snmpwalk_cache_twopart_oid($device, $oid, $array, $mib = 0)
{
    $cmd = gen_snmpwalk_cmd($device, $oid, ' -OQUs', $mib);
    $data = trim(external_exec($cmd));

    foreach (explode("\n", $data) as $entry) {
        list($oid,$value) = explode('=', $entry, 2);
        $oid              = trim($oid);
        $value            = trim($value);
        $value            = str_replace('"', '', $value);
        list($oid, $first, $second) = explode('.', $oid);
        if (!strstr($value, 'at this OID') && isset($oid) && isset($first) && isset($second)) {
            $array[$first][$second][$oid] = $value;
        }
    }

    return $array;
}//end snmpwalk_cache_twopart_oid()


function snmpwalk_cache_threepart_oid($device, $oid, $array, $mib = 0)
{
    global $debug;

    $cmd = gen_snmpwalk_cmd($device, $oid, ' -OQUs', $mib);
    $data = trim(external_exec($cmd));

    foreach (explode("\n", $data) as $entry) {
        list($oid,$value) = explode('=', $entry, 2);
        $oid              = trim($oid);
        $value            = trim($value);
        $value            = str_replace('"', '', $value);
        list($oid, $first, $second, $third) = explode('.', $oid);

        if ($debug) {
            echo "$entry || $oid || $first || $second || $third\n";
        }

        if (!strstr($value, 'at this OID') && isset($oid) && isset($first) && isset($second) && isset($third)) {
            $array[$first][$second][$third][$oid] = $value;
        }
    }

    return $array;
}//end snmpwalk_cache_threepart_oid()


function snmp_cache_slotport_oid($oid, $device, $array, $mib = 0)
{
    $cmd = gen_snmpwalk_cmd($device, $oid, ' -OQUs', $mib);
    $data      = trim(external_exec($cmd));

    foreach (explode("\n", $data) as $entry) {
        $entry                  = str_replace($oid.'.', '', $entry);
        list($slotport, $value) = explode('=', $entry, 2);
        $slotport               = trim($slotport);
        $value                  = trim($value);
        if ($array[$slotport]['ifIndex']) {
            $ifIndex               = $array[$slotport]['ifIndex'];
            $array[$ifIndex][$oid] = $value;
        }
    }

    return $array;
}//end snmp_cache_slotport_oid()


function snmp_cache_oid($oid, $device, $array, $mib = 0)
{
    $array = snmpwalk_cache_oid($device, $oid, $array, $mib);
    return $array;
}//end snmp_cache_oid()


function snmp_cache_port_oids($oids, $port, $device, $array, $mib = 0)
{
    $string = '';
    foreach ($oids as $oid) {
        $string .= " $oid.$port";
    }

    $cmd = gen_snmpget_cmd($device, $string, ' -Ovq', $mib);
    $data   = trim(external_exec($cmd));

    $x      = 0;
    $values = explode("\n", $data);
    // echo("Caching: ifIndex $port\n");
    foreach ($oids as $oid) {
        if (!strstr($values[$x], 'at this OID')) {
            $array[$port][$oid] = $values[$x];
        }

        $x++;
    }

    return $array;
}//end snmp_cache_port_oids()


function snmp_gen_auth(&$device)
{
    global $debug, $vdebug;

    $cmd = '';

    if ($device['snmpver'] === 'v3') {
        $cmd = " -v3 -n '' -l '".$device['authlevel']."'";
        
        //add context if exist context
        if (key_exists('context_name', $device)) {
            $cmd = " -v3 -n '".$device['context_name']."' -l '".$device['authlevel']."'";
        }
        
        if ($device['authlevel'] === 'noAuthNoPriv') {
            // We have to provide a username anyway (see Net-SNMP doc)
            $username = !empty($device['authname']) ? $device['authname'] : 'root';
            $cmd .= " -u '".$username."'";
        } elseif ($device['authlevel'] === 'authNoPriv') {
            $cmd .= " -a '".$device['authalgo']."'";
            $cmd .= " -A '".$device['authpass']."'";
            $cmd .= " -u '".$device['authname']."'";
        } elseif ($device['authlevel'] === 'authPriv') {
            $cmd .= " -a '".$device['authalgo']."'";
            $cmd .= " -A '".$device['authpass']."'";
            $cmd .= " -u '".$device['authname']."'";
            $cmd .= " -x '".$device['cryptoalgo']."'";
            $cmd .= " -X '".$device['cryptopass']."'";
        } else {
            if ($debug) {
                print 'DEBUG: '.$device['snmpver']." : Unsupported SNMPv3 AuthLevel (wtf have you done ?)\n";
            }
        }
    } elseif ($device['snmpver'] === 'v2c' or $device['snmpver'] === 'v1') {
        $cmd  = " -".$device['snmpver'];
        $cmd .= " -c '".$device['community']."'";
    } else {
        if ($debug) {
            print 'DEBUG: '.$device['snmpver']." : Unsupported SNMP Version (shouldn't be possible to get here)\n";
        }
    }//end if

    return $cmd;
}//end snmp_gen_auth()


/*
 * Translate the given MIB into a PHP array.  Each keyword becomes an array index.
 *
 * Example:
 * snmptranslate -Td -On -M mibs -m RUCKUS-ZD-SYSTEM-MIB RUCKUS-ZD-SYSTEM-MIB::ruckusZDSystemStatsNumSta
 * .1.3.6.1.4.1.25053.1.2.1.1.1.15.30
 * ruckusZDSystemStatsAllNumSta OBJECT-TYPE
 *   -- FROM    RUCKUS-ZD-SYSTEM-MIB
 *     SYNTAX   Unsigned32
 *     MAX-ACCESS       read-only
 *     STATUS   current
 *     DESCRIPTION      "Number of All client devices"
 *   ::= { iso(1) org(3) dod(6) internet(1) private(4) enterprises(1) ruckusRootMIB(25053) ruckusObjects(1) ruckusZD(2) ruckusZDSystemModule(1) ruckusZDSystemMIB(1) ruckusZDSystemObjects(1)
 *           ruckusZDSystemStats(15) 30 }
 */
function snmp_mib_parse($oid, $mib, $module, $mibdir = null, $device = array())
{
    $fulloid  = explode('.', $oid);
    $lastpart = end($fulloid);

    $cmd  = 'snmptranslate -Td -On';
    $cmd .= mibdir($mibdir, $device);
    $cmd .= ' -m '.$module.' '.$module.'::';
    $cmd .= $lastpart;

    $result = array();
    $lines  = preg_split('/\n+/', trim(shell_exec($cmd)));
    foreach ($lines as $l) {
        $f = preg_split('/\s+/', trim($l));
        // first line is all numeric
        if (preg_match('/^[\d.]+$/', $f[0])) {
            $result['oid'] = $f[0];
            continue;
        }

        // then the name of the object type
        if ($f[1] && $f[1] == 'OBJECT-TYPE') {
            $result['object_type'] = $f[0];
            continue;
        }

        // then the other data elements
        if ($f[0] == '--' && $f[1] == 'FROM') {
            $result['module'] = $f[2];
            continue;
        }

        if ($f[0] == 'MAX-ACCESS') {
            $result['max_access'] = $f[1];
            continue;
        }

        if ($f[0] == 'STATUS') {
            $result[strtolower($f[0])] = $f[1];
            continue;
        }

        if ($f[0] == 'SYNTAX') {
            $result[strtolower($f[0])] = $f[1];
            continue;
        }

        if ($f[0] == 'DESCRIPTION') {
            $desc = explode('"', $l);
            if ($desc[1]) {
                $str = preg_replace('/^[\s.]*/', '', $desc[1]);
                $str = preg_replace('/[\s.]*$/', '', $str);
                $result[strtolower($f[0])] = $str;
            }

            continue;
        }
    }//end foreach

    // The main mib entry doesn't have any useful data in it - only return items that have the syntax specified.
    if (isset($result['syntax']) && isset($result['object_type'])) {
        $result['mib'] = $mib;
        return $result;
    } else {
        return null;
    }
} // snmp_mib_parse


/*
 * Walks through the given MIB module, looking for the given MIB.
 * NOTE: different from snmp walk - this doesn't touch the device.
 * NOTE: There's probably a better way to do this with snmptranslate.
 *
 * Example:
 * snmptranslate -Ts -M mibs -m RUCKUS-ZD-SYSTEM-MIB | grep ruckusZDSystemStats
 * .iso.org.dod.internet.private.enterprises.ruckusRootMIB.ruckusObjects.ruckusZD.ruckusZDSystemModule.ruckusZDSystemMIB.ruckusZDSystemObjects.ruckusZDSystemStats
 * .iso.org.dod.internet.private.enterprises.ruckusRootMIB.ruckusObjects.ruckusZD.ruckusZDSystemModule.ruckusZDSystemMIB.ruckusZDSystemObjects.ruckusZDSystemStats.ruckusZDSystemStatsNumAP
 * .iso.org.dod.internet.private.enterprises.ruckusRootMIB.ruckusObjects.ruckusZD.ruckusZDSystemModule.ruckusZDSystemMIB.ruckusZDSystemObjects.ruckusZDSystemStats.ruckusZDSystemStatsNumSta
 * ...
 */


function snmp_mib_walk($mib, $module, $mibdir = null, $device = array())
{
    $cmd    = 'snmptranslate -Ts';
    $cmd   .= mibdir($mibdir, $device);
    $cmd   .= ' -m '.$module;
    $result = array();
    $data   = preg_split('/\n+/', shell_exec($cmd));
    foreach ($data as $oid) {
        // only include oids which are part of this mib
        if (strstr($oid, $mib)) {
            $obj = snmp_mib_parse($oid, $mib, $module, $mibdir, $device);
            if ($obj) {
                $result[] = $obj;
            }
        }
    }

    return $result;
} // snmp_mib_walk


function quote_column($a)
{
    return '`'.$a.'`';
} // quote_column


function join_array($a, $b)
{
    return quote_column($a).'='.$b;
} // join_array


/*
 * Update the given table in the database with the given row & column data.
 * @param tablename The table to update
 * @param columns   An array of column names
 * @param numkeys   The number of columns which are in the primary key of the table; these primary keys must be first in the list of columns
 * @param rows      Row data to insert, an array of arrays with column names as the second-level keys
 */
function update_db_table($tablename, $columns, $numkeys, $rows)
{
    dbBeginTransaction();
    foreach ($rows as $nothing => $obj) {
        // create a parameter list based on the columns
        $params = array();
        foreach ($columns as $column) {
            $params[] = $obj[$column];
        }
        $column_placeholders = array_fill(0, count($columns), '?');

        // build the "ON DUPLICATE KEY" part
        $non_key_columns = array_slice($columns, $numkeys);
        $non_key_placeholders = array_slice($column_placeholders, $numkeys);
        $update_definitions = array_map("join_array", $non_key_columns, $non_key_placeholders);
        $non_key_params = array_slice($params, $numkeys);

        $sql = 'INSERT INTO `' . $tablename . '` (' .
            implode(',', array_map("quote_column", $columns)) .
            ') VALUES (' . implode(',', $column_placeholders) .
            ') ON DUPLICATE KEY UPDATE ' . implode(',', $update_definitions);
        $result = dbQuery($sql, array_merge($params, $non_key_params));
        d_echo("Result: $result\n");
    }
    dbCommitTransaction();
} // update_db_table

/*
 * Load the given MIB into the database.
 * @return count of objects loaded
 */
function snmp_mib_load($mib, $module, $included_by, $mibdir = null, $device = array())
{
    $mibs = array();
    foreach (snmp_mib_walk($mib, $module, $mibdir, $device) as $obj) {
        $mibs[$obj['object_type']] = $obj;
        $mibs[$obj['object_type']]['included_by'] = $included_by;
    }
    d_echo($mibs);
    // NOTE: `last_modified` omitted due to being automatically maintained by MySQL
    $columns = array('module', 'mib', 'object_type', 'oid', 'syntax', 'description', 'max_access', 'status', 'included_by');
    update_db_table('mibdefs', $columns, 3, $mibs);
    return count($mibs);
} // snmp_mib_load


/*
 * Turn the given oid (name or numeric value) into a MODULE::mib name.
 * @return an array consisting of the module and mib names, or null if no matching MIB is found.
 * Example:
 * snmptranslate -m all -M mibs .1.3.6.1.4.1.8072.3.2.10 2>/dev/null
 * NET-SNMP-TC::linux
 */
function snmp_translate($oid, $module, $mibdir = null, $device = array())
{
    if ($module !== 'all') {
        $oid = "$module::$oid";
    }

    $cmd  = 'snmptranslate'.mibdir($mibdir, $device);
    $cmd .= " -IR -m $module $oid";
    // load all the MIBs looking for our object
    $cmd .= ' 2>/dev/null';
    // ignore invalid MIBs
    $lines = preg_split('/\n+/', external_exec($cmd));
    if (empty($lines)) {
        d_echo("No results from snmptranslate\n");
        return null;
    }

    $matches = array();
    if (!preg_match('/(.*)::(.*)/', $lines[0], $matches)) {
        d_echo("This doesn't look like a MIB: $lines[0]\n");
        return null;
    }

    d_echo("SNMP translated: $module::$oid -> $matches[1]::$matches[2]\n");
    return array(
        $matches[1],
        $matches[2],
    );
} // snmp_translate


/*
 * check if the type of the oid is a numeric type, and if so,
 * @return the name of RRD type that is best suited to saving it
 */
function oid_rrd_type($oid, $mibdef)
{
    if (!isset($mibdef[$oid])) {
        return false;
    }

    switch ($mibdef[$oid]['syntax']) {
        case 'OCTET':
        case 'IpAddress':
            return false;

        case 'TimeTicks':
            // FIXME
            return false;

        case 'INTEGER':
        case 'Integer32':
            return 'GAUGE:600:U:U';

        case 'Counter32':
        case 'Counter64':
            return 'COUNTER:600:0:U';

        case 'Gauge32':
        case 'Unsigned32':
            return 'GAUGE:600:0:U';
    }

    return false;
} // oid_rrd_type


/*
 * Construct a graph names for use in the database.
 * Tag each as in use on this device in &$graphs.
 * Update the database with graph definitions as needed.
 * We don't include the index in the graph name - that is handled at display time.
 */
function tag_graphs($mibname, $oids, $mibdef, &$graphs)
{
    foreach ($oids as $index => $array) {
        foreach ($array as $oid => $val) {
            $graphname          = $mibname.'-'.$mibdef[$oid]['shortname'];
            $graphs[$graphname] = true;
        }
    }
} // tag_graphs


/*
 * Ensure a graph_type definition exists in the database for the entities in this MIB
 */
function update_mib_graph_types($mibname, $oids, $mibdef, $graphs)
{
    $seengraphs = array();

    // Get the list of graphs currently in the database
    // FIXME: there's probably a more efficient way to do this
    foreach (dbFetch('SELECT DISTINCT `graph_subtype` FROM `graph_types` WHERE `graph_subtype` LIKE ?', array("$mibname-%")) as $graph) {
        $seengraphs[$graph['graph_subtype']] = true;
    }

    foreach ($oids as $index => $array) {
        $i = 1;
        foreach ($array as $oid => $val) {
            $graphname = "$mibname-".$mibdef[$oid]['shortname'];

            // add the graph if it's not in the database already
            if ($graphs[$graphname] && !$seengraphs[$graphname]) {
                // construct a graph definition based on the MIB definition
                $graphdef                  = array();
                $graphdef['graph_type']    = 'device';
                $graphdef['graph_subtype'] = $graphname;
                $graphdef['graph_section'] = 'mib';
                $graphdef['graph_descr']   = $mibdef[$oid]['description'];
                $graphdef['graph_order']   = $i++;
                // TODO: add colours, unit_text, and ds
                // add graph to the database
                dbInsert($graphdef, 'graph_types');
            }
        }
    }
} // update_mib_graph_types


/*
 * Save all of the measurable oids for the device in their own RRDs.
 * Save the current value of all the oids in the database.
 */
function save_mibs($device, $mibname, $oids, $mibdef, &$graphs)
{
    $usedoids = array();
    $deviceoids = array();
    foreach ($oids as $index => $array) {
        foreach ($array as $obj => $val) {
            // build up the device_oid row for saving into the database
            $numvalue = is_numeric($val) ? $val + 0 : 0;
            $deviceoids[] = array(
                'device_id'     => $device['device_id'],
                'oid'           => $mibdef[$obj]['oid'].".".$index,
                'module'        => $mibdef[$obj]['module'],
                'mib'           => $mibdef[$obj]['mib'],
                'object_type'   => $obj,
                'value'         => $val,
                'numvalue'      => $numvalue,
            );

            $type = oid_rrd_type($obj, $mibdef);
            if ($type === false) {
                continue;
            }

            $usedoids[$index][$obj] = $val;

            $tags = array(
                'rrd_def'       => array("DS:mibval:$type"),
                'rrd_name'      => array($mibname, $mibdef[$obj]['shortname'], $index),
                'rrd_oldname'   => array($mibname, $mibdef[$obj]['object_type'], $index),
                'index'         => $index,
                'oid'           => $mibdef[$obj]['oid'],
                'module'        => $mibdef[$obj]['module'],
                'mib'           => $mibdef[$obj]['mib'],
                'object_type'   => $obj,
            );
            data_update($device, 'mibval', $tags, $val);
        }
    }

    tag_graphs($mibname, $usedoids, $mibdef, $graphs);
    update_mib_graph_types($mibname, $usedoids, $mibdef, $graphs);

    // update database
    $columns = array('device_id', 'oid', 'module', 'mib', 'object_type', 'value', 'numvalue');
    update_db_table('device_oids', $columns, 2, $deviceoids);
} // save_mibs


/*
 * @return an array of MIB objects matching $module, $name, keyed by object_type
 */
function load_mibdefs($module, $name)
{
    $params = array($module, $name);
    $result = array();
    $object_types = array();
    foreach (dbFetchRows("SELECT * FROM `mibdefs` WHERE `module` = ? AND `mib` = ?", $params) as $row) {
        $mib = $row['object_type'];
        $object_types[] = $mib;
        $result[$mib] = $row;
    }

    // add shortname to each element
    $prefix = longest_matching_prefix($name, $object_types);
    foreach ($result as $mib => $m) {
        if (strlen($prefix) > 2) {
            $result[$mib]['shortname'] = preg_replace("/^$prefix/", '', $m['object_type'], 1);
        } else {
            $result[$mib]['shortname'] = $m['object_type'];
        }
    }

    d_echo($result);
    return $result;
} // load_mibdefs

/*
 * @return an array of MIB names and modules for $device from the database
 */
function load_device_mibs($device)
{
    $params = array($device['device_id']);
    $result = array();
    foreach (dbFetchRows("SELECT `mib`, `module` FROM device_mibs WHERE device_id = ?", $params) as $row) {
        $result[$row['mib']] = $row['module'];
    }
    return $result;
} // load_device_mibs


/*
 * Run MIB-based polling for $device.  Update $graphs with the results.
 */
function poll_mibs($device, &$graphs)
{
    if (!is_mib_poller_enabled($device)) {
        return;
    }

    echo 'MIB: polling ';
    d_echo("\n");

    foreach (load_device_mibs($device) as $name => $module) {
        echo "$name ";
        d_echo("\n");
        $oids = snmpwalk_cache_oid($device, $name, array(), $module, null, "-OQUsb");
        d_echo($oids);
        save_mibs($device, $name, $oids, load_mibdefs($module, $name), $graphs);
    }
    echo "\n";
} // poll_mibs

/*
 * Take a list of MIB name => module pairs.
 * Validate MIBs and store the device->mib mapping in the database.
 * See includes/discovery/os/ruckuswireless.inc.php for an example of usage.
 */
function register_mibs($device, $mibs, $included_by)
{
    if (!is_mib_poller_enabled($device)) {
        return;
    }

    d_echo("MIB: registering\n");

    foreach ($mibs as $name => $module) {
        $translated = snmp_translate($name, $module, null, $device);
        if ($translated) {
            $mod = $translated[0];
            $nam = $translated[1];
            d_echo("     $mod::$nam\n");
            if (snmp_mib_load($nam, $mod, $included_by, null, $device) > 0) {
                // NOTE: `last_modified` omitted due to being automatically maintained by MySQL
                $columns = array('device_id', 'module', 'mib', 'included_by');
                $rows = array();
                $rows[] = array(
                    'device_id'   => $device['device_id'],
                    'module'      => $mod,
                    'mib'         => $nam,
                    'included_by' => $included_by,
                );
                update_db_table('device_mibs', $columns, 3, $rows);
            } else {
                d_echo("MIB: Could not load definition for $mod::$nam\n");
            }
        } else {
            d_echo("MIB: Could not find $module::$name\n");
        }
    }

    echo "\n";
} // register_mibs

/**
 * SNMPWalk_array_num - performs a numeric SNMPWalk and returns an array containing $count indexes
 * One Index:
 *  From: 1.3.6.1.4.1.9.9.166.1.15.1.1.27.18.655360 = 0
 *  To: $array['1.3.6.1.4.1.9.9.166.1.15.1.1.27.18']['655360'] = 0
 * Two Indexes:
 *  From: 1.3.6.1.4.1.9.9.166.1.15.1.1.27.18.655360 = 0
 *  To: $array['1.3.6.1.4.1.9.9.166.1.15.1.1.27']['18']['655360'] = 0
 * And so on...
 * Think snmpwalk_cache_*_oid but for numeric data.
 *
 * Why is this useful?
 * Some SNMP data contains a single index (eg. ifIndex in IF-MIB) and some is dual indexed
 * (eg. PolicyIndex/ObjectsIndex in CISCO-CLASS-BASED-QOS-MIB).
 * The resulting array allows us to easily access the top level index we want and iterate over the data from there.
 *
 * @param $device
 * @param $OID
 * @param int $indexes
 * @internal param $string
 * @return boolean|array
 */
function snmpwalk_array_num($device, $oid, $indexes = 1)
{
    $array = array();
    $string = snmp_walk($device, $oid, '-Osqn');

    if ($string === false) {
        // False means: No Such Object.
        return false;
    }
    if ($string == "") {
        // Empty means SNMP timeout or some such.
        return null;
    }

    // Let's turn the string into something we can work with.
    foreach (explode("\n", $string) as $line) {
        if ($line[0] == '.') {
            // strip the leading . if it exists.
            $line = substr($line, 1);
        }
        list($key, $value) = explode(' ', $line, 2);
        $prop_id = explode('.', $key);
        $value = trim($value);

        // if we have requested more levels that exist, set to the max.
        if ($indexes > count($prop_id)) {
            $indexes = count($prop_id)-1;
        }

        for ($i=0; $i<$indexes; $i++) {
            // Pop the index off.
            $index = array_pop($prop_id);
            $value = array($index => $value);
        }

        // Rebuild our key
        $key = implode('.', $prop_id);

        // Add the entry to the master array
        $array = array_replace_recursive($array, array($key => $value));
    }
    return $array;
}

/**
 * @param $device
 * @return bool
 */
function get_device_max_repeaters($device)
{
    global $config;

    $max_repeaters = $device['snmp_max_repeaters'];

    if (isset($max_repeaters) && $max_repeaters > 0) {
        return $max_repeaters;
    } elseif (isset($config['snmp']['max_repeaters']) && $config['snmp']['max_repeaters'] > 0) {
        return $config['snmp']['max_repeaters'];
    } else {
        return false;
    }
}
