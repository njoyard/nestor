# This file is part of domserver.
#
# domserver is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# domserver is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with domserver.  If not, see <http://www.gnu.org/licenses/>.

import mpd
import os.path
import threading
import time

from ..errors import ObjectError
from ..objects import ObjectProvider, ObjectProcessor, ObjectWrapper
from ..socketinterfacecodes import SIC
from .media.importer import LobbyWatcherThread
from .media.music import MusicLibrary, MusicTypes, MediaUpdateError


class MPDPlayerObj(ObjectWrapper):
    
    def describe(self):
        self.types = ['mpd-player']
        self.update()
        
    def update(self):
        status = self.provider.mpd_status
        self.props = {
            'state': status.get('state', ''),
            'time': int(status.get('time', '0:0').split(':', 1)[0]),
            'random': int(status.get('random', '0')),
            'repeat': int(status.get('repeat', '0')),
            'volume': int(status.get('volume', '0')),
            'xfade': int(status.get('xfade', '0')),
            'song': int(status.get('song', '-1'))
        }
        
    def set_value(self, key, value):
        raise KeyError("Cannot write to MPDPlayerObj['%s']" % key)


class MusicArtistObj(ObjectWrapper):
    
    def describe(self):
        self.types = ['music-artist']
        
        music = self.provider.music
        artist_id = int(self.oid.split('|', 1)[1])
        meta = music.get_artist_metadata(artist_id)
        self.props = {
            'artist': meta['artist'],
            'artist_id': artist_id,
            'keywords': meta['artist'],
            'path': music.meta_to_filename(meta, MusicTypes.ARTIST)
        }
    
    def update(self):
        # No update needed: we're cleared from cache on metadata change.
        pass
        
    def set_value(self, key, value):
        raise KeyError("Cannot write to MusicArtistObj['%s']" % key)
        
        
class MusicAlbumObj(ObjectWrapper):
    
    def describe(self):    
        self.types = ['music-album']
        
        music = self.provider.music
        album_id = int(self.oid.split('|', 1)[1])
        meta = music.get_album_metadata(album_id)
        self.props = {
            'album': meta['album'],
            'artist': meta['artist'],
            'album_id': album_id,
            'year': int(meta['year']),
            'genre': meta['genre'],
            'keywords': "%s %s" % (meta['artist'], meta['album']),
            'path': music.meta_to_filename(meta, MusicTypes.ALBUM)
        }
    
    def update(self):
        # No update needed: we're cleared from cache on metadata change.
        pass
        
    def set_value(self, key, value):
        raise KeyError("Cannot write to MusicAlbumObj['%s']" % key)


class MusicTrackObj(ObjectWrapper):

    def describe(self):
        kind, id = self.oid.split('|', 1)
        id = int(id)
        self.types = ['music-track']
        
        music = self.provider.music
        playlist = self.provider.mpd_playlist
        status = self.provider.mpd_status
        
        if kind == 'music-track':
            meta = music.get_track_metadata(id)
            rpath = music.meta_to_filename(meta, MusicTypes.TRACK, True)
            track_id = id
            
            try:
                mpd_pos = playlist.index(rpath)
                self.types.append('mpd-item')
            except ValueError:
                mpd_pos = -1
                
        elif kind == 'mpd-item':
            self.types.append('mpd-item')
            rpath = playlist[id].decode('utf-8')
            meta, mtype = music.filename_to_meta(rpath)
            mpd_pos = id
            if not meta:
                self.provider.log.debug("Could not find metadata for %s (path=%s)" % (self.oid, rpath))
                raise ObjectError("invalid-object:%s" % self.oid)
            
            track_id = music.get_track_id(meta['artist'], meta['album'],
                meta['title'])
                
        if status.get('song', -1) != -1 and status.get('song', -1) == mpd_pos:
            playing = 1
        else:
            playing = 0
            
        self.props = {
            'title': meta['title'],
            'mpd-position': mpd_pos,
            'mpd-playing': playing,
            'album': meta['album'],
            'artist': meta['artist'],
            'track_id': track_id,
            'len': int(meta['len']),
            'fmt': meta['fmt'],
            'num': int(meta['num']),
            'year': int(meta['year']),
            'genre': meta['genre'],
            'keywords': "%s %s %s " % (meta['artist'], meta['album'], 
                meta['title']),
            'path': os.path.join(self.domserver.config['media.music_dir'], rpath),
            
            # Not transmitted - just there to avoid re-fetching metadata
            'meta': meta
        }
        
    def update(self):
        kind, id = self.oid.split('|', 1)
        id = int(id)
        music = self.provider.music
        playlist = self.provider.mpd_playlist
                
        # Only update mpd-position (cleared from cache on metadata change)
        if kind == 'music-track':
            rpath = music.meta_to_filename(self.props['meta'], MusicTypes.TRACK, True)
            try:
                mpd_pos = playlist.index(rpath)
                self.types.append('mpd-item')
            except ValueError:
                mpd_pos = -1
                try:
                    self.types.remove('mpd-item')
                except ValueError:
                    pass
            
            self.props['mpd-position'] = mpd_pos
        
    def set_value(self, key, value):
        raise KeyError("Cannot write to MusicTrackObj['%s']" % key)
        

class MLObjectProvider(ObjectProvider):
    """Media library object provider
    
    Provides:
        media:music-artist|<artist id>
        media:music-album|<album id>
        media:music-track|<track id>
        media:mpd
        media:mpd-item|<position>
    """
    
    def __init__(self, domserver, helper, logger=None):
        ObjectProvider.__init__(self, domserver, 'media', logger)
        self.music = helper['music']
        self.mpd = helper['mpd']
        
    def on_query_start(self):
        self.mpd_playlist = self.mpd.playlist()
        self.mpd_status = self.mpd.status()
    
    def on_query_end(self):
        pass
        
    def get_object(self, oid):
        if oid == 'mpd':
            return MPDPlayerObj(self.domserver, self, 'mpd')
            
        try:
            kind, id = oid.split('|', 1)
        except ValueError:
            raise ObjectError("invalid-oid:media:%s" % oid)
            
        if kind == 'music-artist':
            return MusicArtistObj(self.domserver, self, oid)
        elif kind == 'music-album':
            return MusicAlbumObj(self.domserver, self, oid)
        elif kind in ('music-track', 'mpd-item'):
            return MusicTrackObj(self.domserver, self, oid)
        
    def get_oids(self):
        mpd_oids = ['mpd-item|%d' % i for i in range(len(self.mpd_playlist))]
        return ['mpd'] + mpd_oids
        
    def match_oids(self, expr, types):
        oids = []
        types = types or []
        
        if 'music-track' in types:
            track_ids = self.music.match(expr, MusicTypes.TRACK)
            return ['music-track|%d' % i for i in track_ids]
            
        if 'music-album' in types:
            album_ids = self.music.match(expr, MusicTypes.ALBUM)
            return ['music-album|%d' % i for i in album_ids]
            
        if 'music-artist' in types:
            art_ids = self.music.match(expr, MusicTypes.ARTIST)
            return ['music-artist|%d' % i for i in art_ids]
                
        if len(types) == 0 or 'mpd-player' in types or 'mpd-item' in types:
            return ObjectProvider.match_oids(self, expr, types)
            
    def infer_oids(self, obj):
        oids = []
        if obj.owner == 'media':
            oids.append(obj.oid)
            if obj.oid != "mpd":
                kind, id = obj.oid.split("|", 1)
                if kind == 'music-track' and obj["mpd-position"] != -1:
                    oids.append("mpd-item|%d" % obj["mpd-position"])
                if kind == 'mpd-item':
                    oids.append("music-track|%d" % obj["track_id"])
        return oids
        

class MLObjectProcessor(ObjectProcessor):

    def __init__(self, domserver, helper):
        ObjectProcessor.__init__(self, domserver, 'media')
        self.objs = helper['objs']
        self.music = helper['music']
        self.mpd = helper['mpd']
        
    def get_actions(self, obj):
        names = []
        
        if obj.is_a('music-artist'):
            names.extend(['edit-artist', 'remove', 'mpd-play', 'mpd-enqueue'])
        if obj.is_a('music-album'):
            names.extend(['edit-album', 'remove', 'mpd-play', 'mpd-enqueue'])
        if obj.is_a('music-track'):
            names.extend(['edit-track', 'remove', 'mpd-play', 'mpd-enqueue'])
        if obj.is_a('mpd-item'):
            names.extend(['mpd-item-remove', 'mpd-item-move', 'mpd-item-play'])
        if obj.is_a('mpd-player'):
            status = self.objs.mpd_status
            names.extend(['mpd-player-random', 'mpd-player-repeat',
                'mpd-player-clear', 'mpd-player-volume'])
            if status['state'] in ('stop', 'pause'):
                names.extend(['mpd-player-play'])
            if status['state'] in ('pause', 'play'):
                names.extend(['mpd-player-next', 'mpd-player-prev',
                    'mpd-player-seek', 'mpd-player-stop'])
            if status['state'] in ('play'):
                names.extend(['mpd-player-pause'])
            
        return names
        
    def describe(self, act):
        name = act.name
        obj = act.obj
        
        # Metadata edition
        if name == 'edit-artist':
            act.add_param('name', False, obj['artist'])
        elif name == 'edit-album':
            act.add_param('artist', False, obj['artist'])
            act.add_param('title', False, obj['album'])
            act.add_param('year', False, obj['year'])
            act.add_param('genre', False, obj['genre'])
        elif name == 'edit-track':
            act.add_param('artist', False, obj['artist'])
            act.add_param('album', False, obj['album'])
            act.add_param('num', False, obj['num'])
            act.add_param('title', False, obj['title'])
            
        # MPD Player controls
        elif name == 'mpd-player-seek':
            act.add_param('position', False, obj['time'])
        elif name == 'mpd-player-volume':
            act.add_param('volume', False, obj['volume'])
            
        # MPD Playlist controls
        elif name == 'mpd-enqueue':
            act.add_param('position', False, len(self.objs.mpd_playlist))
        elif name == 'mpd-item-move':
            act.add_param('position', False, obj['mpd-position'])
    
    def execute(self, act):
        name = act.name
        obj = act.obj
        
        # Metadata edition
        if name.startswith('edit-'):            
            if name == 'edit-artist':
                meta = self.music.get_artist_metadata(obj['artist_id'])
                meta['artist'] = act['name']
                id = obj['artist_id']
                type = MusicTypes.ARTIST

            elif name == 'edit-album':
                meta = self.music.get_album_metadata(obj['album_id'])
                
                # FIXME add support for SIGNED ints
                if act['year'] >> 31:
                    act['year'] = 2**32 - act['year']
                    
                meta['artist'] = act['artist']
                meta['album'] = act['title']
                meta['year'] = act['year']
                meta['genre'] = act['genre']
                id = obj['album_id']
                type = MusicTypes.ALBUM
            
            elif name == 'edit-track':
                meta = self.music.get_track_metadata(obj['track_id'])
                
                # FIXME add support for SIGNED ints
                if act['num'] >> 31:
                    act['num'] = 2**32 - act['num']
                
                meta['artist'] = act['artist']
                meta['album'] = act['album']
                meta['num'] = act['num']
                meta['title'] = act['title']
                id = obj['track_id']
                type = MusicTypes.TRACK
                
            try:
                arids, alids, trids = self.music.update_metadata(meta, id, type)
            except MediaUpdateError, e:
                raise ObjectError("update-error:%s" % e)
                
            # Invalidate changed objects
            for id in arids:
                o = self.objs.get("music-artist|%d" % id)
                self.objs.cache.invalidate(o)
            for id in alids:
                o = self.objs.get("music-album|%d" % id)
                self.objs.cache.invalidate(o)
            for id in trids:
                o = self.objs.get("music-track|%d" % id)
                self.objs.cache.invalidate(o)
            
        # MPD Player controls
        if name == 'mpd-player-play':
            self.mpd.play()
        elif name == 'mpd-player-pause':
            self.mpd.pause()
        elif name == 'mpd-player-next':
            self.mpd.next()
        elif name == 'mpd-player-prev':
            self.mpd.previous()
        elif name == 'mpd-player-stop':
            self.mpd.stop()
        elif name == 'mpd-player-seek':
            self.mpd.seek(self.objs.mpd_status['song'], act['position'])
        elif name == 'mpd-player-random':
            self.mpd.random(1 - int(self.objs.mpd_status['random']))
        elif name == 'mpd-player-repeat':
            self.mpd.repeat(1 - int(self.objs.mpd_status['repeat']))
        elif name == 'mpd-player-volume':
            self.mpd.setvol(act['volume'])
            
        # MPD Playlist controls
        elif name == 'mpd-player-clear':
            for idx in range(len(self.objs.mpd_playlist)):
                self.objs.cache.remove("media:mpd-item|%d" % idx)
            self.mpd.clear()
        elif name in ('mpd-play', 'mpd-enqueue'):
            mdir = self.domserver.config['media.music_dir']
            if obj['path'].startswith(mdir):
                path = obj['path'][len(mdir):]
                if path.startswith('/'):
                    path = path[1:]
            else:
                return
                
            oldlen = len(self.objs.mpd_playlist)
            if name == 'mpd-play':
                self.mpd.clear()
                
            self.mpd.add(path)
            
            if name == 'mpd-play':
                self.mpd.play(0)
                for idx in range(oldlen):
                    self.objs.cache.remove("media:mpd-item|%d" % idx)
            elif name == 'mpd-enqueue':
                dst = act['position']
                if dst < oldlen:
                    for offset in range(len(self.objs.mpd_playlist) - oldlen):
                        self.mpd.move(oldlen + offset, dst + offset)
                for idx in range(dst, len(self.objs.mpd_playlist)):
                    self.objs.cache.remove("media:mpd-item|%d" % idx)
                
        elif name == 'mpd-item-remove':
            src = obj['mpd-position']
            for idx in range(src, len(self.objs.mpd_playlist)):
                self.objs.cache.remove("media:mpd-item|%d" % idx)
            self.mpd.delete(src)
        elif name == 'mpd-item-move':
            src = obj['mpd-position']
            dst = act['position']
            for idx in range(min(src, dst), max(src, dst) + 1):
                self.objs.cache.remove("media:mpd-item|%d" % idx)
            self.mpd.move(src, dst)
        elif name == 'mpd-item-play':
            self.mpd.play(obj['mpd-position'])
            

class MPDWrapper:
    """MPDClient wrapper using domserver config to connect.  Automatically
    reconnects on failure."""

    _commands = [
        # Playback control
        'play',
        'pause',
        'stop',
        'seek',
        'next',
        'previous',
        
        # Status control
        'status',
        'random',
        'repeat',
        'setvol',
        
        # Playlist control
        'playlist',
        'add',
        'clear',
        'move',
        'delete',
        
        # DB control
        'update'
    ]
    
    def __init__(self, domserver):
        self.domserver = domserver
        self.client = mpd.MPDClient()
        self.cmdlock = threading.Condition(threading.Lock())
        
    def _connect(self):
        try:
            self.client.ping()
        except (mpd.ConnectionError, mpd.ProtocolError):
            try:
                self.client.disconnect()
            except mpd.ConnectionError:
                pass
            self.client.connect(
                self.domserver.config['media.mpd_host'],
                int(self.domserver.config['media.mpd_port'])
            )
            self.client.password(self.domserver.config['media.mpd_password'])
            self._connect()
        
    def _command(self, cmd, *args):
        args_str = []
        for a in args:
            if isinstance(a, unicode):
                args_str.append(a.encode('utf-8'))
            else:
                args_str.append(a)
                
        # The MPD library is not thread-safe, thus we lock here
        self.cmdlock.acquire()
        try:    
            self._connect()
            ret = eval("self.client.%s(*args_str)" % cmd)
        finally:
            self.cmdlock.release()
        return ret
        
    def __getattr__(self, attr):
        if attr in self._commands:
            return lambda *x: self._command(attr, *x)
        else:
            raise AttributeError("MPDWrapper has no '%s' attribute" % attr)
        

class MediaLibraryHelper:

    def __init__(self, domserver):
        self.domserver = domserver
        self.domserver.info("Initializing media library helper")
        self.logger = domserver.get_logger('media.log_file', 'media.log_level')
        
        self.mpd = MPDWrapper(self.domserver)
        self.music = MusicLibrary(self.domserver, self.logger)
        
        self.lw_thread = LobbyWatcherThread(self.domserver, self.logger, self)
        ret = self.domserver.add_thread(self.lw_thread, True)
        
        self.objs = MLObjectProvider(domserver, self, self.logger)
        self.proc = MLObjectProcessor(domserver, self)
        
        domserver.register_object_interface(
            name='media',
            provider=self.objs,
            processor=self.proc
        )
        
    def __getitem__(self, key):
        if key == 'music':
            return self.music
        elif key == 'mpd':
            return self.mpd
        elif key == 'objs':
            return self.objs
        else:
            raise KeyError("MediaLibraryHelper has no key '%s'" % key)
        
