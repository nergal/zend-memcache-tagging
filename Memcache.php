<?php

/**
 * Расширенный адаптер для мемкэша
 *
 * @see http://framework.zend.com/issues/browse/ZF-4253
 * @author Nergal
 */
final class Fan_Cache_Backend_Memcache extends Zend_Cache_Backend_Memcached
{
    /**
     * Префикс ключей кэша
     *
     * @return string
     */
	private function getTagListId()
	{
		return "FanCacheTag";
	}

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
	public function getTags()
	{
		if(!$tags = $this->_memcache->get($this->getTagListId()))
		{
			$tags = array();
		}
		return $tags;
	}

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
	public function getIdsMatchingTags($tags = array())
	{
	    $this->_log('getIdsMatchingTags("' . implode('", "', $tags) . '")');
	    return parent::getIdsMatchingTags($tags);
	}

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
	public function getIdsNotMatchingTags($tags = array())
	{
	    $this->_log('getIdsNotMatchingTags("' . implode('", "', $tags) . '")');
	    return parent::getIdsNotMatchingTags($tags);
	}

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
	public function getIdsMatchingAnyTags($tags = array())
	{
	    $this->_log('getIdsMatchingAnyTags("' . implode('", "', $tags) . '")');
	    return parent::getIdsMatchingAnyTags($tags);
	}

	/**
	 * Сохранение тегов
	 *
	 * @param string $id
	 * @param array $tags
	 */
	private function saveTags($id, $tags)
	{
		// First get the tags
		$siteTags = $this->getTags();

		foreach($tags as $tag)
		{
			$siteTags[$tag][] = $id;
		}
		$this->_memcache->set($this->getTagListId(), $siteTags);
	}

	/**
	 * Возврат записей по тегу
	 *
	 * @param string $tag
	 * @return mixed
	 */
	private function getItemsByTag($tag)
	{
		$siteTags = $this->_memcache->get($this->getTagListId());
		return isset($siteTags[$tag]) ? $siteTags[$tag] : false;
	}

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  int    $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        $lifetime = $this->getLifetime($specificLifetime);
        if ($this->_options['compression']) {
            $flag = MEMCACHE_COMPRESSED;
        } else {
            $flag = 0;
        }
        $result = $this->_memcache->set($id, array($data, time()), $flag, $lifetime);
        if (count($tags) > 0) {
        	$this->saveTags($id, $tags);
        }
        return $result;
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => remove too old cache entries ($tags is not used)
     * 'matchingTag'    => remove cache entries matching all given tags
     *                     ($tags can be an array of strings or a single string)
     * 'notMatchingTag' => remove cache entries not matching one of the given tags
     *                     ($tags can be an array of strings or a single string)
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @return boolean True if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if ($mode==Zend_Cache::CLEANING_MODE_ALL) {
            return $this->_memcache->flush();
        }
        if ($mode==Zend_Cache::CLEANING_MODE_OLD) {
            $this->_log("Fan_Cache_Backend_Memcached::clean() : CLEANING_MODE_OLD is unsupported by the Memcached backend");
        }
        if ($mode==Zend_Cache::CLEANING_MODE_MATCHING_TAG) {
        	$siteTags = $newTags = $this->getTags();

        	if(count($siteTags))
        	{
	        	foreach($tags as $tag)
	        	{
	        		if(isset($siteTags[$tag]))
	        		{
	        			foreach($siteTags[$tag] as $item)
	        			{
	        				// We call delete directly here because the ID in the cache is already specific for this site
	        				$this->_memcache->delete($item);
	        			}
	        			unset($newTags[$tag]);
	        		}
	        	}
	        	$this->_memcache->set($this->getTagListId(),$newTags);
        	}
        }
        if ($mode==Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG) {
        	$siteTags = $newTags = $this->getTags();
        	if(count($siteTags))
        	{
        		foreach($siteTags as $siteTag => $items)
        		{
        			if(array_search($siteTag,$tags) === false)
        			{
        				foreach($items as $item)
        				{
        					$this->_memcache->delete($item);
        				}
        				unset($newTags[$siteTag]);
        			}
        		}
        		$this->_memcache->set($this->getTagListId(),$newTags);
        	}
        }
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        $list = array();
		$allSlabs = $this->_memcache->getExtendedStats('slabs');
		$items = $this->_memcache->getExtendedStats('items');

		foreach($allSlabs as $server => $slabs) {
		    foreach($slabs AS $slabId => $slabMeta) {
		        if (is_numeric($slabId)) {
		            $cdump = $this->_memcache->getExtendedStats('cachedump', (int) $slabId);
		            foreach($cdump AS $server => $entries) {
		                if($entries) {
		                    foreach($entries AS $eName => $eData) {
		                        $list[] = $eName;
		                    }
		                }
		            }
		        }
		    }
		}

		return $list;
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        $mems = $this->_memcache->getExtendedStats();

        $memSize = null;
        $memUsed = null;
        foreach ($mems as $key => $mem) {
            if ($mem === false) {
                $this->_log('can\'t get stat from ' . $key);
                continue;
            }

            $eachSize = $mem['limit_maxbytes'];
            $eachUsed = $mem['bytes'];
            if ($eachUsed > $eachSize) {
                $eachUsed = $eachSize;
            }

            $memSize += $eachSize;
            $memUsed += $eachUsed;
        }

        if ($memSize === null || $memUsed === null) {
            Zend_Cache::throwException('Can\'t get filling percentage');
        }

        return (round(100. * ($memUsed / $memSize), 2));
    }


    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => false,
            'tags' => true,
            'expired_read' => false,
            'priority' => false,
            'infinite_lifetime' => false,
            'get_list' => false
        );
    }
}
