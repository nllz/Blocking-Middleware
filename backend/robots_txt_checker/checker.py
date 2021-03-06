#!/usr/bin/env python2

import os
import sys
import json
import logging
import MySQLdb
import urlparse
import robotparser
import ConfigParser

import requests
import requests_cache

import amqplib.client_0_8 as amqp

logging.basicConfig(
	level=logging.INFO,
	format="%(asctime)s\t%(levelname)s\t%(message)s",
	datefmt="[%Y-%m-%d %H:%M:%S]",
	)

"""
This daemon listens on a dedicated queue for URLs to check.  For each
received URL, the program attempts to fetch the robots.txt file on the
target domain.  If robots.txt indicates that a resource is not available
to spiders, the URL is dropped and the status is written back to the DB.

If robots.txt permits spidering of the target URL, the message is forwarded
to the regular per-isp queues.

The content of the robots.txt file for each domain is cached for <n> days 
(configurable)

This script was written in python to take advantage of the standard library's
robots.txt parser.
"""

class BlockedRobotsTxtChecker(object):
	def __init__(self, config, conn, ch):
		self.config = config
		self.conn = conn
		self.ch = ch
		self.headers = {'User-agent': self.config.get('daemon','useragent')}

	def get_robots_url(self, url):
		"""Split URL, add /robots.txt resource"""
		parts = urlparse.urlparse(url)
		return urlparse.urlunparse( parts[:2] + ('/robots.txt','','','') )

	def set_url_status(self, url, status):
		c = self.conn.cursor()
		c.execute("""update urls set status = %s where url = %s""", [ status, url])
		c.close()
		self.conn.commit()

	def check_robots(self,msg):
		data = json.loads(msg.body)
		self.ch.basic_ack(msg.delivery_tag)

		# get the robots.txt URL
		url = self.get_robots_url(data['url'])
		logging.info("Using robots url: %s", url)
		try:
			# fetch robots.txt
			robots_txt = requests.get(url, headers=self.headers)
			# pass the content to the robots.txt parser
			rbp = robotparser.RobotFileParser()
			rbp.parse(robots_txt.text.splitlines())

			# check to see if we're allowed in - test using OrgProbe's useragent
			if not rbp.can_fetch(self.config.get('daemon','probe_useragent'), data['url']):
				logging.warn("Disallowed: %s", data['url'])
				# write rejection to DB
				self.set_url_status(data['url'], 'disallowed-by-robots-txt')
				return True
			else:
				# we're allowed in.
				logging.info("Allowed: %s", data['url'])
		except Exception,v:
			# if anything bad happens, log it but continue
			logging.error("Exception: %s", v)

		# now do a head request for size and mime type
		try:
			req = requests.head(data['url'], headers=self.headers)
			logging.info("Got mime: %s", req.headers['content-type'])
			if not req.headers['content-type'].startswith('text/'):
				logging.warn("Disallowed MIME: %s", req.headers['content-type'])
				self.set_url_status(data['url'], 'disallowed-mime-type')
				return True

			logging.info("Got length: %s", req.headers.get('content-length',0))
			if int(req.headers.get('content-length',0)) > 262144: # yahoo homepage is 216k!
				#TODO: should we test content of GET request when content-length is not available?
				logging.warn("Content too large: %s", req.headers['content-length'])
				self.set_url_status(data['url'], 'disallowed-content-length')
				return True
		except Exception,v:
			# if anything bad happens, log it but continue
			logging.error("HEAD Exception: %s", v)
				

		# pass the message to the regular location
		msgsend = amqp.Message(msg.body)
		new_key = msg.routing_key.replace('check','url')
		self.ch.basic_publish(msgsend, self.config.get('daemon','exchange'), new_key)
		logging.info("Message sent with new key: %s", new_key)
		return True

def main():

	# set up cache for robots.txt content
	cfg = ConfigParser.ConfigParser()
	assert(len(cfg.read(['config.ini'])) == 1)

	requests_cache.install_cache('robots-txt',expire=cfg.getint('daemon','cache_ttl'))

	# create MySQL connection
	mysqlopts = dict(cfg.items('mysql'))
	conn = MySQLdb.connect(**mysqlopts)

	# Create AMQP connection
	amqpopts = dict(cfg.items('amqp'))
	amqpconn = amqp.Connection( **amqpopts)
	ch = amqpconn.channel()

	checker = BlockedRobotsTxtChecker(cfg, conn, ch)

	# create consumer, enter mainloop
	ch.basic_consume(cfg.get('daemon','queue'), consumer_tag='checker1', callback=checker.check_robots)
	while True:
		ch.wait()

if __name__ == '__main__':
	main()
