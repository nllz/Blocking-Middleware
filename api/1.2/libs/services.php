<?php

class UserLoader {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function load($email) {
		$result = $this->conn->query(
			"select id,secret,probeHMAC,status,administrator from users where email = ?",
			array($email)
			);

		if ($result->num_rows == 0) {
			throw new UserLookupError();
		}
		$row = $result->fetch_assoc();
		return $row;
	}
}

class ProbeLoader {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function load($probe_uuid) {
		$result = $this->conn->query(
			"select * from probes where uuid=?",
			array($probe_uuid)
			);
		if ($result->num_rows == 0) {
			throw new ProbeLookupError();
		}
		$row = $result->fetch_assoc();
		return $row;
	}

	function updateReqSent($probe_uuid, $count=1) {
		# increment the requests sent counter on the probe record
		$result = $this->conn->query(
			"update probes set probeReqSent=probeReqSent+?,lastSeen=now() where uuid=?",
			array($count, $probe_uuid)
			);
		if ($this->conn->affected_rows != 1) {
			throw new ProbeLookupError();
		}
	}

	function updateRespRecv($probe_uuid) {
		# increment the responses recd counter on the probe record
		$result = $this->conn->query(
			"update probes set probeRespRecv=probeRespRecv+1,lastSeen=now() where uuid=?",
			array($probe_uuid)
			);
		if ($this->conn->affected_rows != 1) {
			throw new ProbeLookupError();
		}
	}

}

class UrlLoader {
	function __construct($conn) {
		$this->conn = $conn;
	}

    function insert($url, $source="user") {
        /* Insert user record.  Does not return ID, because of insert-ignore, and 
        because URLs are uniquely indexes only on the first 767 chars */
        $this->conn->query(
            "insert ignore into urls (URL, hash, source, lastPolled, inserted) values (?,?,?,now(), now())",
            array($url, md5($url), $source)
        );
        /* returns true/false for whether a row was really inserted. */
        if ($this->conn->affected_rows) {
            return true;
        } else {
            return false;
        }
        
    }

	function load($url) {
		$result = $this->conn->query(
			"select * from urls where URL=?",
			array($url)
			);
		if ($result->num_rows == 0) {
			throw new UrlLookupError();
		}
		$row = $result->fetch_assoc();
		return $row;
	}

	function checkLastPolled($urlid) {
		# save autocommit state
		$automode = $this->conn->get_autocommit();
		# set autocommit off to allow transaction
		$this->conn->autocommit(false);
		# test lastPolled date in the database
		$result = $this->conn->query(
			"select lastPolled, date_add(lastPolled, INTERVAL 1 DAY) < now()
			from urls where urlID = ?",
			array($urlid)
			);
		$row = $result->fetch_row();

		# if it has never been tested, or the last test < today
		if ($row[0] == null || $row[1] == 1) {
			# update the lastPolled timer inside transaction
			$this->updateLastPolled($urlid);
			$ret = true;
		} else {
			$ret = false;
		}
		# finish transaction with stored result.
		$this->conn->commit();
		#restore autocommit mode
		$this->conn->autocommit($automode);
		return $ret;
	}

	function load_categories($urlID) {
		$result = $this->conn->query(
			"select display_name from categories
			inner join url_categories on category_id = categories.id
			where urlID = ?", array($urlID));
		$out = array();
		while ($row = $result->fetch_row()) {
			$out[] = $row[0];
		}
		return $out;
	}

	function updateLastPolled($urlid) {
		$this->conn->query("update urls set lastPolled=now() where urlID=?",
			array($urlid));
		if ($this->conn->affected_rows != 1) {
			throw new UrlLookupError();
		}
	}
}

class ContactLoader {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function load($email) {
		$result = $this->conn->query(
			"select * from contacts where email=?",
			array($email)
			);
		if ($result->num_rows == 0) {
			throw new UrlLookupError();
		}
		$row = $result->fetch_assoc();
		return $row;
	}

}

class IspLoader {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function load($ispname) {
		$result = $this->conn->query(
			"select isps.* from isps left join isp_aliases on isp_aliases.ispID = isps.id where name = ? or alias = ?",
			array($ispname,$ispname)
			);
		$row = $result->fetch_assoc();
		if (!$row) {
			throw new IspLookupError();
		}
		return $row;
	}

	function create($name) {
		$title = preg_replace('/[^A-Za-z0-9 \-].*$/','',$name);
		// TODO: tidy up module dependency
		$result = $this->conn->query(
			"insert ignore into isps(name,created, description) values (?, now(), ?)",
			array($title, $title)
			);
		if (!$result) {
			throw new DatabaseError();
		}
		$ispid = $this->conn->insert_id;
		$this->conn->query("insert into isp_aliases(ispid, alias, created)
			values (?, ?, now())",
			array($ispid, $name)
		);
		if (!$result) {
			throw new DatabaseError();
		}
		return array('name' => $title);
	}
}

class IpLookupService {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function check_cache($ip) {
		error_log("Checking cache for $ip");
		$result = $this->conn->query(
			"select network from isp_cache where ip = ? and 
			created >= date_sub(current_date, interval 7 day)",
			array($ip)
			);
		if (!$result) {
			return null;
		}
		$row = $result->fetch_assoc();
		if (!$row) {
			return null;
		}
		return $row['network'];
	}

	function write_cache($ip, $network) {
		error_log("Writing cache entry for $ip, $network");
		$this->conn->query(
			"insert into isp_cache(ip, network, created) 
			values (?, ?, now())
			on duplicate key update created = current_date",
		array($ip, $network)
		);
		error_log("Cache write complete");
	}

	function lookup($ip) {
		# run a DNS query for the IP address

		$descr = $this->check_cache($ip);
		if ($descr == null) {
			error_log("Cache miss for $ip");

			if (strpos($ip, ".") !== false) {
				# ipv4 address

				$parts = array_reverse(explode(".", $ip));
				$hostname = implode(".", $parts) . '.origin.asn.cymru.com';
				error_log("Hostname: $hostname");

				$record = dns_get_record($hostname, DNS_TXT);
				if (!$record) {
					throw new IpLookupError();
				}
				error_log("TXT: " .  $record[0]['txt']);
				list($as, $junk) = explode(' ', $record[0]['txt'], 2);

				error_log("AS: $as");

				$ashost = "AS{$as}.asn.cymru.com";
				$record2 = dns_get_record($ashost, DNS_TXT);
				if (!$record) {
					throw new IpLookupError();
				}

				error_log("TXT: " .  $record2[0]['txt']);
				if (!preg_match('/ \| [A-Z0-9\-_]+ (\- )?([^\|]*?)$/', $record2[0]['txt'], $matches)) {
					throw new IpLookupError();
				}
				$descr = $matches[2];
				error_log("Descr: $descr");

			}

			if (!$descr) {
				throw new IpLookupError();
			}
			$this->write_cache($ip, $descr);
		} else {
			error_log("Cache hit");
		}
		error_log("Descr: $descr");
		return $descr;
	}
}

class DMOZCategoryLoader {
    function __construct($conn) {
        $this->conn = $conn;
    }

    function load($id) {
        $res = $this->conn->query("select * from categories where id = ?",
            array($id));
        $row = $res->fetch_assoc();
        return $row;
    }

    function get_lookup_key($parent) {
        // returns a dictionary containing name columns that are in use
        $output = array();
        $last = null;
        for ($i = 1; $i <= 10; $i++) {
            if (trim($parent["name$i"]) != "") {
                $v = $parent["name$i"];
                $output["name$i"] = $parent["name$i"];
            }
        }
        return $output;

    }

    function get_parent($node) {
        error_log("node: " . implode(",", array_keys($node)));
        $key = $this->get_lookup_key($node);
        error_log("key: " . implode(",", array_keys($key)));
        $i = count($key);
        unset($key["name$i"]);

        if (count($key) == 0) {
            return "0";
        }

        $cond = array();
        $args = array();
        
        // build where clause and group by clause
        foreach ($key as $k => $v) {
            if (is_null($v)) {
                $cond[] = "$k is null";
            } else {
                $cond[] = "$k = ?";
                $args[] = $v;
            }
        }
        $n = count($key) ;
        $cond[] = "name$n is not null";
        $where = implode(" and ", $cond);

        $sql = "select id, display_name,
            coalesce(name10,name9,name8,name7,name6,name5,name4,name3,name2,name1) as name,
            sum(blocked_url_count) blocked_url_count,
            sum(block_count) block_count
            from categories 
            where $where
            order by display_name";
        $q = $this->conn->query($sql, $args);
        $row = $q->fetch_assoc();
        return $row['id'];
    }


    function load_children($parent) {
        // get the immediate descendents of a parent category
        $key = $this->get_lookup_key($parent);

        $cond = array();
        $args = array();
        $group = array();
        // build where clause and group by clause
        foreach ($key as $k => $v) {
            $group[] = $k;
            if (is_null($v)) {
                $cond[] = "$k is null";
            } else {
                $cond[] = "$k = ?";
                $args[] = $v;
            }
        }
        if (count($key) < 9) {
            $n = count($key)+2;
            $cond[] = "name$n is null";
        }
        if (count($key) < 10) {
            $n = count($key) + 1;
            $cond[] = "name$n is not null";
            $group[] = "name$n";
        }
        $where = implode(" and ", $cond);
        $groupby = implode(",",$group);
    

        $sql = "select id, display_name,
            coalesce(name10,name9,name8,name7,name6,name5,name4,name3,name2,name1) as name,
            sum(blocked_url_count) blocked_url_count,
            sum(block_count) block_count
            from categories 
            where $where
            group by $groupby
            order by display_name";
        return $this->conn->query($sql, $args);
    }

    function get_counts($parent) {
        // get block_count and blocked_url_count for a category
        $key = $this->get_lookup_key($parent);

        $cond = array();
        $args = array();

        foreach ($key as $k => $v) {
            if (is_null($v)) {
                $cond[] = "$k is null";
            } else {
                $cond[] = "$k = ?";
                $args[] = $v;
            }
        }
        $where = implode(" and ", $cond);
        
        $sql = "select sum(blocked_url_count)  blocked_url_count_total,
        sum(block_count) block_count_total
        from categories
        where $where";
        $ret = $this->conn->query($sql, $args);
        return $ret->fetch_assoc();
    }
        
    function load_toplevel() {
        // get the top-level categories (same format as load_children)
        $sql = "select id, display_name,
            coalesce(name10,name9,name8,name7,name6,name5,name4,name3,name2,name1) as name,
            sum(blocked_url_count) blocked_url_count,
            sum(block_count) block_count
            from categories 
            where name1 is not null and name2 is null
            group by name1
            order by display_name";
        return $this->conn->query($sql, array());


    }

    function load_sites($parent) {
        // get sites that belong to a category (does not get sites of child categories)
        # TODO: unicode function
		$result = $this->conn->query(
			"select URL from urls
			inner join url_categories on urls.urlID = url_categories.urlID
			where category_id = ?", array($parent));
		$out = array();
		while ($row = $result->fetch_row()) {
			$out[] = $row[0];
		}
		return $out;
	}

    function load_blocks($parentid) {
        // get blocked sites that belong to a category (does not get sites of child categories)

        $result = $this->conn->query(
            "select URL as url, count(distinct network_name) block_count
                from urls
            inner join url_categories on urls.urlID = url_categories.urlID
            inner join url_latest_status uls on uls.urlID=urls.urlID
            where url_categories.category_id = ? and uls.status = 'blocked'
            group by url
            order by URL, network_name",
            array($parentid)
            );
        return $result;
    }
}



class ResultProcessorService {
	function __construct($conn, $url_loader, $probe_loader, $isp_loader) {
		$this->conn = $conn;
		$this->url_loader = $url_loader;
		$this->probe_loader = $probe_loader;
		$this->isp_loader = $isp_loader;
	}

	function process_result($result, $probe) {
		# make sure that the named network exists
		$isp = $this->isp_loader->load($result['network_name']);
		$url = $this->url_loader->load($result['url']);

		$this->conn->query(
			"insert into results(urlID,probeID,config,ip_network,status,http_status,network_name, category, blocktype, created) 
			values (?,?,?,?,?,?,?,?,?,now())",
			array(
				$url['urlID'],$probe['id'], $result['config'],$result['ip_network'],
				$result['status'],$result['http_status'], $result['network_name'],
				@$result['category'],@$result['blocktype']
			)
		);

		$this->conn->query(
			"update urls set polledSuccess = polledSuccess + 1 where urlID = ?",
			array($url['urlID'])
			);

		$this->probe_loader->updateRespRecv($probe['uuid']);
	}
}
