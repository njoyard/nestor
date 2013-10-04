/*jshint node:true */
"use strict";

var util = require("util"),
	
	intents = {},
	handlers = {},
	queues = {},
	processing = {},
	
	logger = require("./logger").createLogger("intents");
	
	
function prepareIntent(intent) {
	handlers[intent] = handlers[intent] || [];
}

function processQueue(intent) {
	processing[intent] = true;

	if (!queues[intent].length) {
		logger.debug("Nothing left to process for intent %s", intent);
		processing[intent] = false;
		return;
	}

	var index = 0,
		handlersCopy,
		args = queues[intent].shift();

	logger.debug("Processing intent %s (%s)", intent, args ? util.inspect(args) : "no data");

	prepareIntent(intent);
	handlersCopy = handlers[intent].slice();
	
	function next(param) {
		if (param !== false && handlersCopy[index]) {
			handlersCopy[index++](args, next);
		} else {
			// No more handlers or stop requested with next(false)
			if (index === 0) {
				logger.warn("No handlers for intent %s", intent);
			}

			processQueue(intent);
		}
	}
	
	next();
}

intents.register = function(intent, handler) {
	var index;
	
	prepareIntent(intent);
	index = handlers[intent].indexOf(handler);
	if (index === -1) {
		handlers[intent].push(handler);
	}
};

intents.unregister = function(intent, handler) {
	var index;
	
	prepareIntent(intent);
	index = handlers[intent].indexOf(handler);
	if (index !== -1) {
		handlers[intent].splice(index, 1);
	}
};

intents.dispatch = function(intent, args) {
	if (!queues[intent]) {
		queues[intent] = [];
	}

	logger.debug("Intent %s dispatched (%s)", intent, args ? util.inspect(args) : "no data");
	queues[intent].push(args);

	if (!processing[intent]) processQueue(intent);
};

module.exports = intents;