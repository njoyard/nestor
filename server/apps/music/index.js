/*jshint node:true */
"use strict";

var ffprobe = require("node-ffprobe"),
	when = require("when"),

	track = require("./track"),
	playlist = require("./playlist"),

	Track = track.model;


/* Media analysis handler */
function analyzeFile(nestor, args, next) {
	var path = args.path;

	function error(action, err) {
		nestor.logger.error("Could not " + action  + ": %s", path, err.message + "\n" + err.stack);
	}

	ffprobe(path, function ffprobeHandler(err, data) {
		if (err) {
			error("probe file %s", err);
			next();
			return;
		}
		
		if (!data.streams || data.streams.length != 1 || data.streams[0].codec_type !== "audio") {
			// Unknown file type
			next();
		} else {
			var meta = data.metadata || { title: "", artist: "", album:	"", track: "", date: "" };
			var trackdata = {
				path: path,
				title: meta.title || "",
				artist: meta.artist || "",
				album: meta.album || "",
				number: parseInt(meta.track, 10),
				year: parseInt(meta.date, 10),
				
				format: data.format.format_name,
				bitrate: data.format.bit_rate,
				length: data.format.duration
			};
			
			if (isNaN(trackdata.number)) {
				trackdata.number = -1;
			}
			
			if (isNaN(trackdata.year)) {
				trackdata.year = -1;
			}

			Track.findOne({ path: path }, function(err, track) {
				if (err) {
					error("find track %s", err);
					next(false);
					return;
				}

				if (track) {
					track.update(trackdata, function(err) {
						if (err) {
							error("update track %s", err);
						}

						next(false);
					});
				} else {
					track = new Track(trackdata);
					track.save(function(err) {
						if (err) {
							error("save track %s", err);
						}

						next(false);
					});
				}
			});
		}
	});
}


exports.init = function(nestor) {
	nestor.intents.register("media.analyzeFile", analyzeFile.bind(null, nestor));

	track.restSetup(nestor.rest);
	playlist.restSetup(nestor.rest);

	return when.resolve();
};

exports.manifest = {
	description: "Music library",
	deps: [ "media" ],
	clientApps: [ "music" ]
};
