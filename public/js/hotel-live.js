/*
 * Live updates and voice calls for the hotel's web pages (staff Guest Chat, the guest's booking page).
 *
 * HotelLive: a small Pusher-protocol client (Laravel Reverb or a hosted Pusher-compatible service). Private
 * channels are signed by the hotel (POST authUrl). It reconnects by itself; pages also poll, so a dropped
 * socket only makes things a little slower, never lost.
 *
 * HotelCall: one WebRTC voice call. Signalling (offer / answer / ICE candidates) goes through the hotel's API;
 * the voice itself goes phone-to-browser directly or through the TURN relay. A call put through to someone
 * else starts a new "leg": the guest's side connects afresh to whoever takes it. On the hotel side, the
 * call also feeds the sound to a supervisor listening in from the call console (a "tap"), when asked.
 *
 * HotelListen: the call console's side of listening in (receives the tap; never sends a microphone).
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

    /** A server that trims form fields drops the SDP's last line break, which browsers refuse: put it back. */
    function fixSdp(s) {
        if (s && s.payload && typeof s.payload.sdp === 'string' && s.payload.sdp && !/\r\n$/.test(s.payload.sdp)) s.payload.sdp = s.payload.sdp.replace(/\r?\n?$/, '') + '\r\n';
    }

    /*
     * opts: {call (JSON from the API), base (URL of /calls/{uuid}), csrf, onState(text, phase), onEnd(), audio (HTMLAudioElement),
     *        mic (a microphone deviceId, e.g. a headset), onInfo(call JSON), onLevel(them, me), onQuality(q)}
     * Phases: connecting, ringing, live, ended.
     */
    function HotelCall(opts) {
        this.o = opts;
        this.call = opts.call;
        this.after = 0;
        this.afterTap = 0;
        this.taps = {};
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
        var audio = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
        if (this.o.mic) audio.deviceId = { ideal: this.o.mic };
        return navigator.mediaDevices.getUserMedia({ audio: audio, video: false })
            .then(function (stream) {
                self.stream = stream;
                var offered = self.connect();
                self.poll();
                return offered;
            });
    };

    /** A peer connection for the current leg (again after a transfer, keeping the microphone). */
    HotelCall.prototype.connect = function () {
        var self = this, stream = this.stream;
        var pc = self.pc = new RTCPeerConnection({ iceServers: self.call.ice_servers || [] });
        stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });
        pc.ontrack = function (e) {
            self.remote = e.streams[0];
            if (self.o.audio) { self.o.audio.srcObject = e.streams[0]; self.o.audio.play().catch(function () {}); }
            self.meter();
        };
        pc.onicecandidate = function (e) { if (e.candidate && pc === self.pc) self.post('/signal', { kind: 'candidate', payload: e.candidate.toJSON(), leg: self.call.leg }).catch(function () {}); };
        pc.onconnectionstatechange = function () {
            if (pc !== self.pc) return;
            clearTimeout(self.dropT);
            if (pc.connectionState === 'connected') { self.state('Connected', 'live'); self.startedAt = self.startedAt || Date.now(); self.watch(); }
            // A blip (Wi-Fi to mobile data, a lift): give it a few seconds, then renegotiate the route.
            if (pc.connectionState === 'disconnected') { self.state('Reconnecting…', 'live'); self.dropT = setTimeout(function () { self.restart(); }, 4000); }
            if (pc.connectionState === 'failed') { self.state('The connection dropped. Reconnecting…', 'connecting'); self.restart(); }
        };
        if (self.call.i_offer) {
            return pc.createOffer({ offerToReceiveAudio: true }).then(function (o) { return pc.setLocalDescription(o).then(function () { return self.post('/signal', { kind: 'offer', payload: { type: o.type, sdp: o.sdp }, leg: self.call.leg }); }); });
        }
    };

    /** The call was put through to someone new: the guest's side connects again (the old route is dropped). */
    HotelCall.prototype.newLeg = function (c) {
        var old = this.pc;
        this.call.leg = c.leg; this.call.i_offer = c.i_offer;
        this.seen = {}; this.pending = []; this.after = 0;
        clearInterval(this.watchT); this.watchT = null;
        if (this.ctx) { try { this.ctx.close(); } catch (e) {} this.ctx = null; clearInterval(this.meterT); }
        if (old) try { old.close(); } catch (e) {}
        this.startedAt = this.startedAt || Date.now();
        if (this.stream) this.connect();
    };

    /** Find a new network route without hanging up (only the caller offers, so both sides don't at once). */
    HotelCall.prototype.restart = function () {
        var self = this, pc = this.pc;
        if (this.ended || !pc || !this.call.i_offer || pc.connectionState === 'connected') return;
        if (pc.restartIce) pc.restartIce();
        pc.createOffer({ iceRestart: true }).then(function (o) { return pc.setLocalDescription(o).then(function () { return self.post('/signal', { kind: 'offer', payload: { type: o.type, sdp: o.sdp }, leg: self.call.leg }); }); }).catch(function () {});
    };

    /** How loud each side is (0–1), about ten times a second, for the call screen's sound bars. */
    HotelCall.prototype.meter = function () {
        var Ctx = w.AudioContext || w.webkitAudioContext, self = this;
        if (!Ctx || !this.o.onLevel || this.ctx) return;
        try {
            var ctx = this.ctx = new Ctx();
            var tap = function (stream) { if (!stream) return null; var a = ctx.createAnalyser(); a.fftSize = 256; ctx.createMediaStreamSource(stream).connect(a); return a; };
            var them = tap(this.remote), me = tap(this.stream), buf = new Uint8Array(128);
            var level = function (a) { if (!a) return 0; a.getByteTimeDomainData(buf); var peak = 0; for (var i = 0; i < buf.length; i++) peak = Math.max(peak, Math.abs(buf[i] - 128)); return Math.min(1, peak / 64); };
            this.meterT = setInterval(function () { if (!self.ended) self.o.onLevel(level(them), self.muted ? 0 : level(me)); }, 100);
        } catch (e) {}
    };

    /** Call quality from the connection's own numbers (round trip, lost packets): 3 good, 2 fair, 1 poor. */
    HotelCall.prototype.watch = function () {
        var self = this;
        if (this.watchT || !this.o.onQuality) return;
        var lost0 = 0, got0 = 0;
        this.watchT = setInterval(function () {
            if (self.ended || !self.pc) return;
            self.pc.getStats().then(function (stats) {
                var rtt = null, lost = 0, got = 0;
                stats.forEach(function (r) {
                    if (r.type === 'candidate-pair' && r.nominated && r.currentRoundTripTime != null) rtt = r.currentRoundTripTime;
                    if (r.type === 'inbound-rtp' && r.kind === 'audio') { lost = r.packetsLost || 0; got = r.packetsReceived || 0; }
                });
                var dl = lost - lost0, dg = got - got0, loss = dg + dl > 0 ? dl / (dg + dl) : 0; lost0 = lost; got0 = got;
                var q = (rtt != null && rtt > 0.6) || loss > 0.08 ? 1 : ((rtt != null && rtt > 0.3) || loss > 0.03 ? 2 : 3);
                self.o.onQuality(q, { rtt: rtt, loss: loss });
            }).catch(function () {});
        }, 2000);
    };

    /** Signals from the other side (pushed in by the socket, or found by polling). */
    HotelCall.prototype.handle = function (s) {
        if (!s || this.seen[s.id] || this.ended) return;
        fixSdp(s);
        this.seen[s.id] = true; this.after = Math.max(this.after, s.id);
        var self = this, pc = this.pc;
        if (!pc) { this.pending.push(s); return; }
        if (s.kind === 'offer') {
            pc.setRemoteDescription(s.payload).then(function () { self.flush(); return pc.createAnswer(); })
                .then(function (a) { return pc.setLocalDescription(a).then(function () { return self.post('/signal', { kind: 'answer', payload: { type: a.type, sdp: a.sdp }, leg: self.call.leg }); }); })
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
        fetch(this.o.base + '?after=' + this.after + '&after_tap=' + this.afterTap, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var c = j.data || {};
                self.update(c);
                if (self.ended) return;
                (c.signals || []).forEach(function (s) { self.handle(s); });
                if (c.taps) self.serveTaps(c.taps, c.tap_signals || []);
            })
            .catch(function () {})
            .then(function () { if (!self.ended) setTimeout(function () { self.poll(); }, self.pc && self.pc.connectionState === 'connected' ? 3000 : 1000); });
    };

    /** The call's status changed (ringing → active → ended). */
    HotelCall.prototype.update = function (c) {
        if (!c || !c.status) return;
        if (c.leg && this.call.leg && c.leg !== this.call.leg && !this.ended) {
            // Put through to someone else: the hotel side's part is over; the guest's side reconnects.
            if (this.call.side !== 'guest') {
                return this.finish('Put through to ' + ((c.transfer && c.transfer.to) || 'someone else'));
            }
            this.newLeg(c);
            this.state('Putting you through' + (c.transfer && c.transfer.to ? ' to ' + c.transfer.to : '') + '…', 'ringing');
        }
        this.call.status = c.status;
        this.call.listeners = c.listeners;
        if (this.o.onInfo) this.o.onInfo(c);
        if (c.status === 'ringing') {
            this.state(this.call.leg > 1 ? 'Putting you through' + (c.transfer && c.transfer.to ? ' to ' + c.transfer.to : '') + '…' : 'Ringing…', 'ringing');
            // Only the caller has a call running while it rings: they hear it ring, so the silence never feels like a dead line.
            if (!this.stopBack && this.o.ringback !== false) this.stopBack = ringTone('back');
        } else if (this.stopBack) { this.stopBack(); this.stopBack = null; }
        if (c.status === 'active' && !(this.pc && this.pc.connectionState === 'connected')) this.state((c.answered_by ? c.answered_by + ' answered. ' : '') + 'Connecting…', 'connecting');
        if (['ended', 'missed', 'declined', 'failed'].indexOf(c.status) >= 0) this.finish({ ended: 'Call ended', missed: 'No answer', declined: 'They couldn’t take the call', failed: 'The call failed' }[c.status]);
    };

    // ── Feeding a supervisor who listens in (hotel side only) ────────

    HotelCall.prototype.serveTaps = function (ids, signals) {
        var self = this, want = {};
        ids.forEach(function (id) { want[id] = true; if (!self.taps[id]) self.openTap(id); });
        for (var id in this.taps) if (!want[id]) this.closeTap(id);
        signals.forEach(function (s) {
            fixSdp(s);
            self.afterTap = Math.max(self.afterTap, s.id);
            var t = self.taps[s.listen]; if (!t) return;
            if (s.kind === 'answer') t.pc.setRemoteDescription(s.payload).then(function () { t.ready = true; t.queue.forEach(function (c) { t.pc.addIceCandidate(c).catch(function () {}); }); t.queue = []; }).catch(function () {});
            else if (s.kind === 'candidate') { if (t.ready) t.pc.addIceCandidate(s.payload).catch(function () {}); else t.queue.push(s.payload); }
        });
    };

    /** Both voices, mixed, to the listener — they hear the call, they are never heard. */
    HotelCall.prototype.openTap = function (id) {
        var Ctx = w.AudioContext || w.webkitAudioContext, self = this;
        if (!Ctx || !this.stream || !w.RTCPeerConnection) return;
        try {
            var ctx = new Ctx(), dest = ctx.createMediaStreamDestination();
            [this.remote, this.stream].forEach(function (st) { if (st) ctx.createMediaStreamSource(st).connect(dest); });
            var pc = new RTCPeerConnection({ iceServers: this.call.ice_servers || [] }), t = this.taps[id] = { pc: pc, ctx: ctx, queue: [], ready: false };
            dest.stream.getAudioTracks().forEach(function (tr) { pc.addTrack(tr, dest.stream); });
            var send = function (kind, payload) { return self.post('/tap/' + id, { kind: kind, payload: payload }); };
            pc.onicecandidate = function (e) { if (e.candidate) send('candidate', e.candidate.toJSON()).catch(function () {}); };
            pc.createOffer().then(function (o) { return pc.setLocalDescription(o).then(function () { return send('offer', { type: o.type, sdp: o.sdp }); }); })
                .catch(function () { self.closeTap(id); });
        } catch (e) {}
    };

    HotelCall.prototype.closeTap = function (id) {
        var t = this.taps[id]; if (!t) return;
        delete this.taps[id];
        try { t.pc.close(); } catch (e) {}
        try { t.ctx.close(); } catch (e) {}
    };

    HotelCall.prototype.hangUp = function (reason) {
        if (this.ended) return;
        this.post('/end', { reason: reason || 'hung_up' }).catch(function () {});
        this.finish('Call ended');
    };

    HotelCall.prototype.mute = function (on) {
        this.muted = !!on;
        if (this.stream) this.stream.getAudioTracks().forEach(function (t) { t.enabled = !on; });
    };

    HotelCall.prototype.finish = function (text) {
        if (this.ended) return;
        this.ended = true;
        clearInterval(this.meterT); clearInterval(this.watchT); clearTimeout(this.dropT);
        for (var id in this.taps) this.closeTap(id);
        if (this.stopBack) { this.stopBack(); this.stopBack = null; }
        if (this.ctx) try { this.ctx.close(); } catch (e) {}
        if (this.pc) try { this.pc.close(); } catch (e) {}
        if (this.stream) this.stream.getTracks().forEach(function (t) { t.stop(); });
        this.state(text, 'ended');
        if (this.o.onEnd) this.o.onEnd();
    };

    HotelCall.prototype.state = function (text, phase) { if (this.o.onState) this.o.onState(text, phase, this); };

    HotelCall.prototype.seconds = function () { return this.startedAt ? Math.round((Date.now() - this.startedAt) / 1000) : 0; };

    // ── Listening in from the call console ───────────────────────────

    /*
     * opts: {listen (JSON from POST …/listen), base (URL of /it/console/listens/{id}), csrf, audio (HTMLAudioElement),
     *        onState(text, phase), onLevel(level), onEnd(text)}
     */
    function HotelListen(opts) {
        this.o = opts; this.l = opts.listen; this.after = 0; this.ended = false; this.queue = [];
    }

    HotelListen.prototype.post = HotelCall.prototype.post;

    HotelListen.prototype.start = function () {
        var self = this;
        if (!w.RTCPeerConnection) return Promise.reject(new Error('This browser can’t listen to calls.'));
        var pc = this.pc = new RTCPeerConnection({ iceServers: this.l.ice_servers || [] });
        pc.ontrack = function (e) {
            if (self.o.audio) { self.o.audio.srcObject = e.streams[0]; self.o.audio.play().catch(function () {}); }
            var Ctx = w.AudioContext || w.webkitAudioContext;
            if (Ctx && self.o.onLevel) try {
                var ctx = self.ctx = new Ctx(), a = ctx.createAnalyser(); a.fftSize = 512; ctx.createMediaStreamSource(e.streams[0]).connect(a);
                self.analyser = a;
                var buf = new Uint8Array(256);
                self.meterT = setInterval(function () { a.getByteTimeDomainData(buf); var p = 0; for (var i = 0; i < buf.length; i++) p = Math.max(p, Math.abs(buf[i] - 128)); self.o.onLevel(Math.min(1, p / 64)); }, 80);
            } catch (er) {}
        };
        pc.onicecandidate = function (e) { if (e.candidate) self.post('/signal', { kind: 'candidate', payload: e.candidate.toJSON() }).catch(function () {}); };
        pc.onconnectionstatechange = function () { if (pc.connectionState === 'connected') self.state('Listening', 'live'); if (pc.connectionState === 'failed') self.state('The sound didn’t get through. Try again.', 'connecting'); };
        this.state('Asking the hotel side for the sound…', 'connecting');
        this.waitT = setTimeout(function () { if (!self.gotOffer && !self.ended) self.state('No sound yet — this works when the call was answered on the website (not the staff app).', 'connecting'); }, 15000);
        this.poll();
        return Promise.resolve();
    };

    HotelListen.prototype.poll = function () {
        var self = this;
        if (this.ended) return;
        fetch(this.o.base + '?after=' + this.after, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var l = j.data || {};
                if (l.status === 'ended') return self.finish({ call_ended: 'The call ended', transferred: 'The call was put through to someone else', stopped: 'Stopped listening' }[l.end_reason] || 'Stopped listening');
                (l.signals || []).forEach(function (s) { self.handle(s); });
            })
            .catch(function () {})
            .then(function () { if (!self.ended) setTimeout(function () { self.poll(); }, self.pc && self.pc.connectionState === 'connected' ? 3000 : 1000); });
    };

    HotelListen.prototype.handle = function (s) {
        var self = this, pc = this.pc;
        fixSdp(s);
        this.after = Math.max(this.after, s.id);
        if (s.kind === 'offer') {
            this.gotOffer = true;
            pc.setRemoteDescription(s.payload).then(function () { self.queue.forEach(function (c) { pc.addIceCandidate(c).catch(function () {}); }); self.queue = []; return pc.createAnswer(); })
                .then(function (a) { return pc.setLocalDescription(a).then(function () { return self.post('/signal', { kind: 'answer', payload: { type: a.type, sdp: a.sdp } }); }); })
                .then(function () { self.state('Connecting…', 'connecting'); }).catch(function () {});
        } else if (s.kind === 'candidate') {
            if (pc.remoteDescription) pc.addIceCandidate(s.payload).catch(function () {}); else this.queue.push(s.payload);
        }
    };

    HotelListen.prototype.stop = function () {
        if (this.ended) return;
        this.post('/end', {}).catch(function () {});
        this.finish('Stopped listening');
    };

    HotelListen.prototype.finish = function (text) {
        if (this.ended) return;
        this.ended = true;
        clearInterval(this.meterT); clearTimeout(this.waitT);
        if (this.ctx) try { this.ctx.close(); } catch (e) {}
        if (this.pc) try { this.pc.close(); } catch (e) {}
        this.state(text, 'ended');
        if (this.o.onEnd) this.o.onEnd(text);
    };

    HotelListen.prototype.state = function (text, phase) { if (this.o.onState) this.o.onState(text, phase); };

    /** A soft two-tone ring (no sound file needed); kind 'back' is the quieter ringing a caller hears. Returns a stop function. */
    function ringTone(kind) {
        var Ctx = w.AudioContext || w.webkitAudioContext; if (!Ctx) return function () {};
        var ctx = new Ctx(), on = true;
        function back() {
            if (!on) return;
            var o = ctx.createOscillator(), o2 = ctx.createOscillator(), g = ctx.createGain(), t = ctx.currentTime;
            o.frequency.value = 400; o2.frequency.value = 450;
            g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.06, t + 0.05);
            g.gain.setValueAtTime(0.06, t + 0.4); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.45);
            g.gain.setValueAtTime(0.0001, t + 0.6); g.gain.exponentialRampToValueAtTime(0.06, t + 0.65);
            g.gain.setValueAtTime(0.06, t + 1.0); g.gain.exponentialRampToValueAtTime(0.0001, t + 1.05);
            o.connect(g); o2.connect(g); g.connect(ctx.destination);
            o.start(t); o2.start(t); o.stop(t + 1.1); o2.stop(t + 1.1);
            setTimeout(back, 3000);
        }
        if (kind === 'back') { back(); return function () { on = false; try { ctx.close(); } catch (e) {} }; }
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

    // ── The call screen ──────────────────────────────────────────────

    /*
     * A call card that floats over the page: who, the state and timer, sound bars for both sides, the line
     * quality, and mute / end. It can shrink to a pill so the guest or the staff can keep chatting.
     * ui = new HotelCallUI({title, subtitle, initials, icon}); then pass ui.hooks() into HotelCall, and ui.bind(call).
     */
    var CSS = '.hcu{position:fixed;z-index:2000;right:20px;bottom:20px;width:min(340px,calc(100vw - 24px));color:#fff;border-radius:26px;' +
        'background:radial-gradient(120% 90% at 20% 0%,var(--hcu-glow,#3b2f1c) 0%,var(--hcu-ink,#0f172a) 62%);box-shadow:0 30px 70px rgba(0,0,0,.45);' +
        'padding:22px 20px 18px;font-family:inherit;animation:hcuIn .35s cubic-bezier(.2,.9,.3,1.2)}' +
        '.hcu.min{width:auto;padding:8px 10px 8px 14px;border-radius:99px;display:flex;align-items:center;gap:10px;cursor:pointer}' +
        '.hcu.min .hcu-big,.hcu.min .hcu-acts,.hcu.min .hcu-top{display:none}.hcu:not(.min) .hcu-pill{display:none}' +
        '.hcu-top{display:flex;justify-content:space-between;align-items:center;font-size:12px;opacity:.8;letter-spacing:.12em;text-transform:uppercase}' +
        '.hcu-top button{background:rgba(255,255,255,.12);border:0;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer}' +
        '.hcu-big{text-align:center;padding:14px 0 6px}.hcu-av{position:relative;width:96px;height:96px;margin:6px auto 14px;border-radius:50%;display:grid;place-items:center;' +
        'font-size:34px;font-weight:600;background:linear-gradient(135deg,var(--hcu-gold-l,#e3c48f),var(--hcu-gold,#b8955a));box-shadow:0 10px 30px rgba(0,0,0,.35)}' +
        '.hcu-av:before,.hcu-av:after{content:"";position:absolute;inset:-10px;border-radius:50%;border:2px solid var(--hcu-gold,#b8955a);opacity:0}' +
        '.hcu.ringing .hcu-av:before,.hcu.connecting .hcu-av:before{animation:hcuRing 2.2s infinite}.hcu.ringing .hcu-av:after,.hcu.connecting .hcu-av:after{animation:hcuRing 2.2s 1.1s infinite}' +
        '.hcu-name{font-size:24px;font-weight:600;line-height:1.15;font-family:var(--hcu-serif,inherit)}.hcu-sub{font-size:13px;opacity:.7;margin-top:2px}' +
        '.hcu-xfer{margin-top:12px;background:rgba(0,0,0,.25);border-radius:14px;padding:8px}.hcu-xfer input{width:100%;border:0;border-radius:9px;padding:7px 10px;margin-bottom:6px;font:inherit;font-size:13px}' +
        '.hcu-xl{max-height:180px;overflow:auto;display:grid;gap:4px;font-size:13px}.hcu-xl button{display:grid;grid-template-columns:20px 1fr;text-align:left;gap:0 6px;border:0;border-radius:9px;padding:6px 8px;background:rgba(255,255,255,.1);color:#fff;cursor:pointer;font:inherit}' +
        '.hcu-xl button small{grid-column:2;opacity:.65;font-size:11px}.hcu-xl button:hover{background:rgba(255,255,255,.2)}' +
        '.hcu-st{margin-top:10px;font-size:14px;opacity:.9;min-height:20px;font-variant-numeric:tabular-nums}.hcu-note{margin:8px auto 0;max-width:270px;font-size:11.5px;line-height:1.35;opacity:.72;border-top:1px solid rgba(255,255,255,.14);padding-top:8px}' +
        '.hcu-bars{display:flex;justify-content:center;align-items:center;gap:4px;height:34px;margin:12px 0 4px}.hcu-bars i{display:block;width:4px;border-radius:3px;background:var(--hcu-gold-l,#e3c48f);height:4px;transition:height .1s}' +
        '.hcu-q{display:inline-flex;gap:2px;align-items:flex-end;height:12px;margin-left:6px;vertical-align:middle}.hcu-q b{display:block;width:3px;border-radius:1px;background:rgba(255,255,255,.3)}' +
        '.hcu-q b:nth-child(1){height:5px}.hcu-q b:nth-child(2){height:8px}.hcu-q b:nth-child(3){height:12px}.hcu-q[data-q="1"] b:nth-child(1){background:#f87171}' +
        '.hcu-q[data-q="2"] b:nth-child(-n+2){background:#fbbf24}.hcu-q[data-q="3"] b{background:#4ade80}' +
        '.hcu-acts{display:flex;justify-content:center;gap:22px;margin-top:16px}.hcu-acts button{width:58px;height:58px;border-radius:50%;border:0;cursor:pointer;font-size:24px;color:#fff;' +
        'background:rgba(255,255,255,.14);transition:transform .12s,background .2s}.hcu-acts button:active{transform:scale(.92)}.hcu-acts .on{background:#fff;color:#0f172a}' +
        '.hcu-acts .end{background:#dc2626;width:66px;height:66px}.hcu-acts label{display:block;font-size:11.5px;opacity:.75;margin-top:6px;text-align:center}' +
        '.hcu-pill .dot{width:9px;height:9px;border-radius:50%;background:#4ade80;display:inline-block;animation:hcuPulse 1.6s infinite}.hcu-pill button{border:0;background:#dc2626;color:#fff;width:34px;height:34px;border-radius:50%;cursor:pointer}' +
        '@keyframes hcuIn{from{opacity:0;transform:translateY(20px) scale(.96)}to{opacity:1;transform:none}}' +
        '@keyframes hcuRing{0%{transform:scale(.9);opacity:.7}100%{transform:scale(1.5);opacity:0}}@keyframes hcuPulse{50%{opacity:.35}}' +
        '@media (prefers-reduced-motion:reduce){.hcu,.hcu-av:before,.hcu-av:after,.hcu-pill .dot{animation:none!important}}' +
        '@media (max-width:560px){.hcu:not(.min){left:12px;right:12px;bottom:12px;width:auto}}';

    function HotelCallUI(opts) {
        this.o = opts || {};
        if (!document.getElementById('hcu-css')) { var st = document.createElement('style'); st.id = 'hcu-css'; st.textContent = CSS; document.head.appendChild(st); }
        var el = this.el = document.createElement('div');
        el.className = 'hcu connecting'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Call with ' + (opts.title || ''));
        var esc = function (x) { return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
        var bars = ''; for (var i = 0; i < 9; i++) bars += '<i></i>';
        el.innerHTML = '<div class="hcu-top"><span>' + esc(opts.label || 'Voice call') + '<span class="hcu-q" title="Line quality"><b></b><b></b><b></b></span></span><button type="button" data-act="min" aria-label="Make smaller">–</button></div>' +
            '<div class="hcu-big"><div class="hcu-av">' + (opts.icon ? '<i class="' + esc(opts.icon) + '"></i>' : esc(opts.initials || '•')) + '</div>' +
            '<div class="hcu-name">' + esc(opts.title) + '</div>' + (opts.subtitle ? '<div class="hcu-sub">' + esc(opts.subtitle) + '</div>' : '') +
            '<div class="hcu-bars" aria-hidden="true">' + bars + '</div><div class="hcu-st" aria-live="polite">Connecting…</div><div class="hcu-note" hidden></div></div>' +
            '<div class="hcu-acts"><div><button type="button" data-act="mute" aria-label="Mute"><i class="ri-mic-line"></i></button><label>Mute</label></div>' +
            (opts.transfer ? '<div><button type="button" data-act="xfer" aria-label="Put the call through to someone"><i class="ri-arrow-left-right-line"></i></button><label>Transfer</label></div>' : '') +
            '<div><button type="button" class="end" data-act="end" aria-label="End the call"><i class="ri-phone-fill" style="display:inline-block;transform:rotate(135deg)"></i></button><label>End</label></div></div>' +
            (opts.transfer ? '<div class="hcu-xfer" hidden><input type="search" placeholder="Who to put it through to…" aria-label="Search"><div class="hcu-xl"></div></div>' : '') +
            '<div class="hcu-pill"><span class="dot"></span> <strong>' + esc(opts.title) + '</strong> <span class="hcu-pt"></span> <button type="button" data-act="end" aria-label="End the call"><i class="ri-phone-fill" style="display:inline-block;transform:rotate(135deg)"></i></button></div>';
        document.body.appendChild(el);
        var self = this;
        el.addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b) { if (el.classList.contains('min')) el.classList.remove('min'); return; }
            e.stopPropagation();
            var act = b.dataset.act;
            if (act === 'min') el.classList.add('min');
            if (act === 'end') { if (self.call && !self.call.ended) self.call.hangUp(); else self.close(); }
            if (act === 'xfer') self.openTransfer();
            if (act === 'to' && self.call) {
                var t = { type: b.dataset.type, id: +b.dataset.id };
                b.disabled = true;
                self.o.transfer.put(self.call.call.uuid, t).then(function () { self.el.querySelector('.hcu-xfer').hidden = true; self.status('Putting it through…', 'ringing'); })
                    .catch(function (er) { b.disabled = false; self.status(er.message || 'That didn’t work.', self.phase); });
            }
            if (act === 'mute' && self.call) { var m = !self.call.muted; self.call.mute(m); b.classList.toggle('on', m); b.innerHTML = '<i class="ri-mic' + (m ? '-off' : '') + '-line"></i>'; b.nextElementSibling.textContent = m ? 'Unmute' : 'Mute'; }
        });
        this.clock = setInterval(function () { self.tick(); }, 1000);
    }

    HotelCallUI.prototype.hooks = function () {
        var self = this;
        return {
            onState: function (text, phase) { self.status(text, phase); },
            onLevel: function (them, me) { self.level(them, me); },
            onQuality: function (q) { self.el.querySelector('.hcu-q').dataset.q = q; },
            onInfo: function (c) { self.info(c); },
        };
    };

    /** The monitoring notice for guests; for the hotel side, who is listening in. */
    HotelCallUI.prototype.info = function (c) {
        var n = this.el.querySelector('.hcu-note'), text = c.notice || ((c.listeners && c.listeners.length) ? '🎧 ' + c.listeners.join(', ') + ' listening in' : '');
        n.hidden = !text; n.textContent = text;
    };

    HotelCallUI.prototype.bind = function (call) { this.call = call; };

    /** The people and sections the call can go to (opts.transfer = {targets(uuid) → Promise, put(uuid, {type, id}) → Promise}). */
    HotelCallUI.prototype.openTransfer = function () {
        var box = this.el.querySelector('.hcu-xfer'), list = box.querySelector('.hcu-xl'), q = box.querySelector('input'), self = this;
        if (!box.hidden) { box.hidden = true; return; }
        box.hidden = false; list.textContent = 'Loading…';
        var esc = function (x) { return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
        this.o.transfer.targets(this.call.call.uuid).then(function (t) {
            var draw = function () {
                var f = q.value.trim().toLowerCase(), all = (t.sections || []).concat(t.people || []).filter(function (x) { return !f || (x.label + ' ' + (x.sub || '')).toLowerCase().indexOf(f) >= 0; });
                list.innerHTML = all.map(function (x) { return '<button type="button" data-act="to" data-type="' + x.type + '" data-id="' + x.id + '"><i class="' + esc(x.icon || 'ri-user-line') + '"></i> <b>' + esc(x.label) + '</b><small>' + esc(x.sub || '') + '</small></button>'; }).join('') || 'Nobody matches';
            };
            q.oninput = draw; draw(); q.focus();
        }).catch(function (e) { list.textContent = e.message || 'Couldn’t load.'; });
    };

    HotelCallUI.prototype.status = function (text, phase) {
        this.phase = phase;
        this.el.className = this.el.className.replace(/\b(connecting|ringing|live|ended)\b/g, '').trim() + ' ' + (phase || '');
        this.text = text;
        this.tick();
        if (phase === 'ended') { var self = this; this.level(0, 0); setTimeout(function () { self.close(); }, 2200); }
    };

    HotelCallUI.prototype.tick = function () {
        var s = this.call && this.call.seconds ? this.call.seconds() : 0;
        var clock = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        var show = this.phase === 'live' && s && this.text === 'Connected' ? clock : this.text;
        this.el.querySelector('.hcu-st').textContent = show || '';
        this.el.querySelector('.hcu-pt').textContent = this.phase === 'live' && s ? clock : (this.text || '');
    };

    /** The bars rise with whoever is speaking (theirs in the middle, a little of yours at the edges). */
    HotelCallUI.prototype.level = function (them, me) {
        var bars = this.el.querySelectorAll('.hcu-bars i'), n = bars.length, mid = (n - 1) / 2;
        for (var i = 0; i < n; i++) {
            var d = Math.abs(i - mid) / mid, v = Math.max(them * (1 - d * 0.6), me * d * 0.8);
            bars[i].style.height = (4 + v * 30 * (0.75 + Math.random() * 0.25)) + 'px';
        }
    };

    HotelCallUI.prototype.close = function () {
        clearInterval(this.clock);
        if (this.el.parentNode) this.el.parentNode.removeChild(this.el);
        if (this.o.onClose) this.o.onClose();
    };

    // ── Voice messages ───────────────────────────────────────────────

    /*
     * Records a voice message from the microphone: rec = HotelVoiceNote.start({max: 120, onTick(seconds, level)});
     * rec.stop() → Promise<{blob, seconds, name}>; rec.cancel(). Opus in webm (Chrome, Firefox) or mp4 (Safari).
     */
    var HotelVoiceNote = {
        supported: function () { return !!(navigator.mediaDevices && w.MediaRecorder); },
        start: function (o) {
            o = o || {};
            var max = o.max || 120, chunks = [], started = Date.now(), done, fail, timer, ctx, an;
            var ready = new Promise(function (res, rej) { done = res; fail = rej; });
            var rec = { cancelled: false };
            rec.promise = navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).then(function (stream) {
                var type = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].filter(function (t) { return MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t); })[0];
                var mr = rec.mr = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
                mr.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
                mr.onstop = function () {
                    clearInterval(timer); stream.getTracks().forEach(function (t) { t.stop(); }); if (ctx) try { ctx.close(); } catch (e) {}
                    if (rec.cancelled) return fail(new Error('cancelled'));
                    var mime = (mr.mimeType || type || 'audio/webm').split(';')[0], blob = new Blob(chunks, { type: mime });
                    done({ blob: blob, seconds: Math.max(1, Math.round((Date.now() - started) / 1000)), name: 'voice.' + (mime.indexOf('mp4') >= 0 ? 'm4a' : (mime.indexOf('ogg') >= 0 ? 'ogg' : 'webm')) });
                };
                var Ctx = w.AudioContext || w.webkitAudioContext, buf = new Uint8Array(128);
                if (Ctx) try { ctx = new Ctx(); an = ctx.createAnalyser(); an.fftSize = 256; ctx.createMediaStreamSource(stream).connect(an); } catch (e) {}
                started = Date.now(); mr.start(250);
                timer = setInterval(function () {
                    var s = (Date.now() - started) / 1000, lv = 0;
                    if (an) { an.getByteTimeDomainData(buf); for (var i = 0; i < buf.length; i++) lv = Math.max(lv, Math.abs(buf[i] - 128) / 64); }
                    if (o.onTick) o.onTick(Math.floor(s), Math.min(1, lv));
                    if (s >= max) rec.stop();
                }, 100);
            });
            rec.stop = function () { return rec.promise.then(function () { if (rec.mr.state !== 'inactive') rec.mr.stop(); return ready; }); };
            rec.cancel = function () { rec.cancelled = true; rec.promise.then(function () { if (rec.mr.state !== 'inactive') rec.mr.stop(); }).catch(function () {}); ready.catch(function () {}); };
            return rec;
        },
        /** A small player for a voice message in a chat bubble. */
        html: function (m, esc) {
            return '<div class="hv-note"><audio controls preload="none" src="' + esc(m.audio) + '"></audio><span class="hv-len">' + esc((m.audio_label || '').replace(/^.*\(|\)$/g, '')) + '</span></div>';
        },
    };

    w.HotelVoiceNote = HotelVoiceNote;
    w.HotelCallUI = HotelCallUI;
    w.HotelLive = HotelLive;
    w.HotelCall = HotelCall;
    w.HotelListen = HotelListen;
    w.hotelRingTone = ringTone;
})(window);
