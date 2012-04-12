/*
 * Copyright 2010-2012 Nicolas Joyard
 *
 * This file is part of nestor.
 *
 * nestor is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * nestor is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with nestor.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Web server setup
 */

/*jslint white: true, plusplus: true */
"use strict";

var express = require('express'),
	authHandler = require('./auth');

module.exports = function(staticDirs, sessionStore, cookieSecret, sessionExpireDays) {
	var web = express.createServer(),
		oneDay = 1000 * 60 * 60 * 24;

	/* Express configuration */
	web.configure(function() {
		web.use(express.favicon());
		web.use(express.logger({
			immediate: true,
			format: 'dev'
		}));
		
		web.use(express.bodyParser());
	
		web.use(express.cookieParser());
		web.use(express.session({
			secret: cookieSecret,
			store: sessionStore,
			cookie: { maxAge: oneDay * sessionExpireDays }
		}));
		
		// Static dir handlers
		staticDirs.forEach(function(dir) {
			web.use(express.static(dir));
		});
		
		// JSON response helper
		web.use(function(req, res, next) {
			res.json = function(response) {
				res.setHeader('Content-Type', 'application/json');
				res.end(JSON.stringify(response));
			};
		
			next();
		});
		
		// Auth handler for all non-static requests
		web.use(authHandler);
		
		// Web router
		web.use(web.router);
	});
	
	return web;
};