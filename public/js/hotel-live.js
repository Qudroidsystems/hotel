/*
 * Live updates and voice calls for the hotel's web pages (staff Guest Chat, the guest's booking page).
 *
 * HotelLive: a small Pusher-protocol client (Laravel Reverb or a hosted Pusher-compatible service). Private
 * channels are signed by the hotel (POST authUrl). It reconnects by itself; pages also poll, so a dropped
 * socket only makes things a little slower, never lost.
 *
 * HotelCall: one WebRTC voice call. Signalling (offer / answer / ICE candidates) goes through the hotel's API;
 * the voice itself goes phone-to-browser directly or through the TURN relay.
 */
(function (w) {
    'use strict';

    function HotelLive(opts) {
        this.o = opts;               // {config:{enabled,url}, channels:[], authUrl, authExtra:{}, csrf, onEvent(channel, event, data), onState(bool)}
        this.ws = null;
        this.tries = 0;
        this.up = false;
        this.stopped = false;
        if (opts.config && opts.config.enabled && 'WebSocket' in w) this.connect();
    }

    HotelLive.prototype.connect = function () {
        var self = this;
        try { this.ws = new WebSocket(this.o.config.url); } catch (e) { return this.retry(); }
        this.ws.onmessage = function (m) {
            var msg; try { msg = JSON.parse(m.data); } catch (e) { return; }
            var data = msg.data; if (typeof data === 'string') { try { data = JSON.parse(data); } catch (e) {} }
            if (msg.event === 'pusher:connection_established') {
                self.tries = 0; self.socketId = data.socket_id; self.setUp(true);
                (self.o.channels || []).forEach(function (c) { self.subscribe(c); });
            } else if (msg.event === 'pusher:ping') {
                self.send({ event: 'pusher:pong', data: {} });
            } else if (msg.event && msg.event.indexOf('pusher') !== 0 && self.o.onEvent) {
                self.o.onEvent(msg.channel, msg.event, data);
            }
        };
        this.ws.onclose = function () { self.setUp(false); if (!self.stopped) self.retry(); };
        this.ws.onerror = function () { try { self.ws.close(); } catch (e) {} };
    };

    HotelLive.prototype.retry = function () {
        var self = this, wait = Math.min(30000, 1000 * Math.pow(2, this.tries++)) + Math.random() * 1000;
        setTimeout(function () { if (!self.stopped) self.connect(); }, wait);
    };

    HotelLive.prototype.setUp = function (v) { this.up = v; if (this.o.onState) this.o.onState(v); };

    HotelLive.prototype.send = function (obj) { if (this.ws && this.ws.readyState === 1) this.ws.send(JSON.stringify(obj)); };

    HotelLive.prototype.subscribe = function (channel) {
        var self = this, body = Object.assign({ socket_id: this.socketId, channel_name: channel }, this.o.authExtra || {});
        fetch(this.o.authUrl, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.o.csrf || '' }, body: JSON.stringify(body) })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (a) { if (a && a.auth) self.send({ event: 'pusher:subscribe', data: { auth: a.auth, channel: channel } }); })
            .catch(function () {});
    };

    HotelLive.prototype.stop = function () { this.stopped = true; if (this.ws) try { this.ws.close(); } catch (e) {} };

    // ── Calls ────────────────────────────────────────────────────────

    /*
     * opts: {call (JSON from the API), base (URL of /calls/{uuid}), csrf, onState(text, phase), onEnd(), audio (HTMLAudioElement)}
     * Phases: connecting, ringing, live, ended.
     */
    function HotelCall(opts) {
        this.o = opts;
        this.call = opts.call;
        this.after = 0;
        this.ended = false;
        this.seen = {};
        this.pending = [];
    }

    HotelCall.prototype.post = function (path, body) {
        return fetch(this.o.base + path, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.o.csrf || '' }, body: JSON.stringify(body || {}) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || 'The call didn’t go through.'); return j; }); });
    };

    /** Start the audio side: microphone, peer connection, and (for the caller) the offer. */
    HotelCall.prototype.start = function () {
        var self = this;
        if (!navigator.mediaDevices || !w.RTCPeerConnection) return Promise.reject(new Error('This browser can’t make calls. Use the phone number instead.'));
        this.state('Starting the microphone…', 'connecting');
        return navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: false })
            .then(function (stream) {
                self.stream = stream;
                var pc = self.pc = new RTCPeerConnection({ iceServers: self.call.ice_servers || [] });
                stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });
                pc.ontrack = function (e) { if (self.o.audio) { self.o.audio.srcObject = e.streams[0]; self.o.audio.play().catch(function () {}); } };
                pc.onicecandidate = function (e) { if (e.candidate) self.post('/signal', { kind: 'candidate', payload: e.candidate.toJSON() }).catch(function () {}); };
                pc.onconnectionstatechange = function () {
                    if (pc.connectionState === 'connected') { self.state('Connected', 'live'); self.startedAt = self.startedAt || Date.now(); }
                    if (pc.connectionState === 'failed') self.state('The connection dropped. Trying again…', 'connecting');
                };
                self.poll();
                if (self.call.i_offer) {
                    return pc.createOffer({ offerToReceiveAudio: true }).then(function (o) { return pc.setLocalDescription(o).then(function () { return self.post('/signal', { kind: 'offer', payload: { type: o.type, sdp: o.sdp } }); }); });
                }
            });
    };

    /** Signals from the other side (pushed in by the socket, or found by polling). */
    HotelCall.prototype.handle = function (s) {
        if (!s || this.seen[s.id] || this.ended) return;
        this.seen[s.id] = true; this.after = Math.max(this.after, s.id);
        var self = this, pc = this.pc;
        if (!pc) { this.pending.push(s); return; }
        if (s.kind === 'offer') {
            pc.setRemoteDescription(s.payload).then(function () { self.flush(); return pc.createAnswer(); })
                .then(function (a) { return pc.setLocalDescription(a).then(function () { return self.post('/signal', { kind: 'answer', payload: { type: a.type, sdp: a.sdp } }); }); })
                .catch(function () {});
        } else if (s.kind === 'answer') {
            pc.setRemoteDescription(s.payload).then(function () { self.flush(); }).catch(function () {});
        } else if (s.kind === 'candidate') {
            if (pc.remoteDescription) pc.addIceCandidate(s.payload).catch(function () {}); else this.pending.push(s);
        }
    };

    HotelCall.prototype.flush = function () {
        var list = this.pending; this.pending = [];
        var self = this; list.forEach(function (s) { delete self.seen[s.id]; self.handle(s); });
    };

    /** Ask for the call's state and new signals: every second while connecting, every 3 once live. */
    HotelCall.prototype.poll = function () {
        var self = this;
        if (this.ended) return;
        fetch(this.o.base + '?after=' + this.after, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var c = j.data || {};
                (c.signals || []).forEach(function (s) { self.handle(s); });
                self.update(c);
            })
            .catch(function () {})
            .then(function () { if (!self.ended) setTimeout(function () { self.poll(); }, self.pc && self.pc.connectionState === 'connected' ? 3000 : 1000); });
    };

    /** The call's status changed (ringing → active → ended). */
    HotelCall.prototype.update = function (c) {
        if (!c || !c.status) return;
        this.call.status = c.status;
        if (c.status === 'ringing') this.state('Ringing…', 'ringing');
        if (c.status === 'active' && !(this.pc && this.pc.connectionState === 'connected')) this.state((c.answered_by ? c.answered_by + ' answered. ' : '') + 'Connecting…', 'connecting');
        if (['ended', 'missed', 'declined', 'failed'].indexOf(c.status) >= 0) this.finish({ ended: 'Call ended', missed: 'No answer', declined: 'They couldn’t take the call', failed: 'The call failed' }[c.status]);
    };

    HotelCall.prototype.hangUp = function (reason) {
        if (this.ended) return;
        this.post('/end', { reason: reason || 'hung_up' }).catch(function () {});
        this.finish('Call ended');
    };

    HotelCall.prototype.mute = function (on) {
        if (this.stream) this.stream.getAudioTracks().forEach(function (t) { t.enabled = !on; });
    };

    HotelCall.prototype.finish = function (text) {
        if (this.ended) return;
        this.ended = true;
        if (this.pc) try { this.pc.close(); } catch (e) {}
        if (this.stream) this.stream.getTracks().forEach(function (t) { t.stop(); });
        this.state(text, 'ended');
        if (this.o.onEnd) this.o.onEnd();
    };

    HotelCall.prototype.state = function (text, phase) { if (this.o.onState) this.o.onState(text, phase, this); };

    HotelCall.prototype.seconds = function () { return this.startedAt ? Math.round((Date.now() - this.startedAt) / 1000) : 0; };

    /** A soft two-tone ring (no sound file needed). Returns a stop function. */
    function ringTone() {
        var Ctx = w.AudioContext || w.webkitAudioContext; if (!Ctx) return function () {};
        var ctx = new Ctx(), on = true;
        function burst() {
            if (!on) return;
            [0, 0.25].forEach(function (t) {
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.frequency.value = 440 + (t ? 40 : 0); g.gain.setValueAtTime(0.0001, ctx.currentTime + t);
                g.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + t + 0.03); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + t + 0.22);
                o.connect(g).connect(ctx.destination); o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.25);
            });
            setTimeout(burst, 2000);
        }
        burst();
        return function () { on = false; try { ctx.close(); } catch (e) {} };
    }

    w.HotelLive = HotelLive;
    w.HotelCall = HotelCall;
    w.hotelRingTone = ringTone;
})(window);
