/**
 * Shared Zoom Client View — lazy asset load, lightweight init, screen-share layout.
 * Scripts load after boot screen (avoids blocking parse + OOM on low-memory tabs).
 */
(function (global) {
  'use strict';

  var shareLayoutApplied = false;
  var shareListenersBound = false;
  var participantListenersBound = false;
  var dockTimer = null;
  var assetsLoadPromise = null;
  var preparePromise = null;
  var initPromise = null;

  function errMsg(err) {
    if (!err || typeof err !== 'object') return 'Zoom error';
    return err.reason || err.message || 'Zoom error';
  }

  function loadStylesheet(href) {
    return new Promise(function (resolve, reject) {
      if (document.querySelector('link[data-fm-zoom-css="1"]')) {
        resolve();
        return;
      }
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.setAttribute('data-fm-zoom-css', '1');
      link.onload = function () { resolve(); };
      link.onerror = function () { reject(new Error('Failed to load Zoom CSS')); };
      document.head.appendChild(link);
    });
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var existing = document.querySelector('script[data-fm-zoom-src="' + src + '"]');
      if (existing) {
        if (existing.getAttribute('data-fm-loaded') === '1') {
          resolve();
          return;
        }
        existing.addEventListener('load', function () { resolve(); }, { once: true });
        existing.addEventListener('error', function () { reject(new Error('Failed to load ' + src)); }, { once: true });
        return;
      }
      var script = document.createElement('script');
      script.src = src;
      script.async = false;
      script.setAttribute('data-fm-zoom-src', src);
      script.onload = function () {
        script.setAttribute('data-fm-loaded', '1');
        resolve();
      };
      script.onerror = function () { reject(new Error('Failed to load ' + src)); };
      document.head.appendChild(script);
    });
  }

  /**
   * Load React + Zoom SDK only after boot UI is visible (sequential, one bundle at a time).
   */
  function loadZoomAssets(assetBase, meetingJs, zoomCssHref) {
    if (typeof ZoomMtg !== 'undefined') {
      return Promise.resolve();
    }
    if (assetsLoadPromise) {
      return assetsLoadPromise;
    }

    var base = String(assetBase || '').replace(/\/$/, '');
    var jsFile = meetingJs || 'zoom-meeting-6.2.0.min.js';
    var cssHref = zoomCssHref || (base + '/dist/ui/zoom-meetingsdk.css');

    assetsLoadPromise = loadStylesheet(cssHref)
      .then(function () { return loadScript(base + '/vendor/react.min.js'); })
      .then(function () { return loadScript(base + '/vendor/react-dom.min.js'); })
      .then(function () { return loadScript(base + '/vendor/redux.min.js'); })
      .then(function () { return loadScript(base + '/vendor/redux-thunk.min.js'); })
      .then(function () { return loadScript(base + '/dist/' + jsFile); })
      .then(function () {
        if (typeof ZoomMtg === 'undefined') {
          throw new Error('Zoom SDK failed to initialize.');
        }
      });

    return assetsLoadPromise;
  }

  function debouncedDock() {
    if (dockTimer) window.clearTimeout(dockTimer);
    dockTimer = window.setTimeout(function () {
      dockTimer = null;
      dockParticipantTiles();
    }, 800);
  }

  function dockParticipantTiles() {
    var root = document.getElementById('zmmtg-root');
    if (!root) return;

    var nodes = root.querySelectorAll(
      '[class*="video-avatar"], [class*="VideoAvatar"], [class*="self-video"], [class*="SelfVideo"]'
    );
    for (var i = 0; i < nodes.length && i < 8; i++) {
      var el = nodes[i];
      if (!el || el.closest('[class*="share"], [class*="Share"]')) continue;
      var rect = el.getBoundingClientRect();
      if (rect.width < 40 || rect.height < 40) continue;
      if (rect.width > window.innerWidth * 0.6) continue;
      el.classList.add('fm-docked-participant-tile');
    }
  }

  function isShareContentVisible() {
    var root = document.getElementById('zmmtg-root');
    if (!root) return false;
    var share = root.querySelector(
      '[class*="share-content"], [class*="ShareContent"], [class*="sharing-layout"], [class*="SharingLayout"]'
    );
    if (!share) return false;
    var rect = share.getBoundingClientRect();
    return rect.width > 80 && rect.height > 80;
  }

  function applyPureShareLayout() {
    if (!isShareContentVisible()) {
      clearShareLayout();
      return;
    }
    if (shareLayoutApplied) {
      debouncedDock();
      return;
    }
    shareLayoutApplied = true;
    document.body.classList.add('fm-zoom-share-active');
    if (typeof ZoomMtg !== 'undefined' && typeof ZoomMtg.showPureSharingContent === 'function') {
      try {
        ZoomMtg.showPureSharingContent({ show: true });
      } catch (e) { /* ignore */ }
    }
    debouncedDock();
  }

  function clearShareLayout() {
    shareLayoutApplied = false;
    document.body.classList.remove('fm-zoom-share-active');
    var root = document.getElementById('zmmtg-root');
    if (root) {
      root.querySelectorAll('.fm-docked-participant-tile').forEach(function (el) {
        el.classList.remove('fm-docked-participant-tile');
      });
    }
    if (typeof ZoomMtg !== 'undefined' && typeof ZoomMtg.showPureSharingContent === 'function') {
      try {
        ZoomMtg.showPureSharingContent({ show: false });
      } catch (e) { /* ignore */ }
    }
  }

  function setParticipantCountClasses(count) {
    document.body.classList.toggle('fm-multi-participant', count >= 2);
    document.body.classList.toggle('fm-participant-count-2', count === 2);
    document.body.classList.toggle('fm-participant-count-3plus', count >= 3);
    if (count >= 3) {
      ensureGalleryView(count);
    } else {
      document.body.classList.remove('fm-gallery-active');
    }
  }

  function isCrossOriginIsolated() {
    return typeof window.crossOriginIsolated !== 'undefined' && window.crossOriginIsolated === true;
  }

  function clickGalleryViewButton() {
    var root = document.getElementById('zmmtg-root');
    if (!root) return false;

    var candidates = root.querySelectorAll('button, [role="button"], [role="menuitem"]');
    for (var i = 0; i < candidates.length; i++) {
      var el = candidates[i];
      var label = (
        el.getAttribute('aria-label') ||
        el.getAttribute('title') ||
        el.textContent ||
        ''
      ).toLowerCase();
      if (label.indexOf('gallery') !== -1 && label.indexOf('side-by-side') === -1) {
        el.click();
        return true;
      }
    }
    return false;
  }

  var galleryEnsureTimer = null;
  function ensureGalleryView(count) {
    if (count < 3) return;

    document.body.classList.add('fm-participant-count-3plus', 'fm-gallery-active');
    document.body.classList.toggle('fm-gallery-blocked', !isCrossOriginIsolated());

    if (!isCrossOriginIsolated()) {
      return;
    }

    if (galleryEnsureTimer) window.clearTimeout(galleryEnsureTimer);
    galleryEnsureTimer = window.setTimeout(function () {
      galleryEnsureTimer = null;
      clickGalleryViewButton();
      window.setTimeout(clickGalleryViewButton, 1500);
    }, 600);
  }

  function applyVideoOrderLayout(data) {
    if (!data) return;
    var view = data.view || '';
    if (view === 'gallery-view') {
      document.body.classList.add('fm-gallery-active');
    }
    var galleryCount = Array.isArray(data.galleryMainCurrent) ? data.galleryMainCurrent.length : 0;
    var barCount = Array.isArray(data.speakerBarCurrent) ? data.speakerBarCurrent.length : 0;
    var activeCount = Array.isArray(data.speakerActiveCurrent) ? data.speakerActiveCurrent.length : 0;
    var total = Math.max(galleryCount, barCount + activeCount);
    if (total >= 3) {
      setParticipantCountClasses(total);
    }
  }

  function refreshParticipantLayout() {
    if (typeof ZoomMtg === 'undefined' || typeof ZoomMtg.getAttendeeslist !== 'function') return;
    try {
      ZoomMtg.getAttendeeslist({
        success: function (list) {
          var count = Array.isArray(list) ? list.length : 0;
          setParticipantCountClasses(count);
        }
      });
    } catch (e) { /* ignore */ }
  }

  function bindParticipantViewListeners() {
    if (participantListenersBound || typeof ZoomMtg === 'undefined') return;
    if (typeof ZoomMtg.inMeetingServiceListener !== 'function') return;
    participantListenersBound = true;

    try {
      ZoomMtg.inMeetingServiceListener('onUserJoin', function () {
        window.setTimeout(refreshParticipantLayout, 400);
      });
    } catch (e) { /* ignore */ }

    try {
      ZoomMtg.inMeetingServiceListener('onUserLeave', function () {
        window.setTimeout(refreshParticipantLayout, 400);
      });
    } catch (e) { /* ignore */ }

    try {
      ZoomMtg.inMeetingServiceListener('onVideoOrder', function (data) {
        applyVideoOrderLayout(data);
      });
    } catch (e) { /* ignore */ }

    try {
      ZoomMtg.inMeetingServiceListener('onMeetingStatus', function (data) {
        if (data && data.status === 2) {
          window.setTimeout(refreshParticipantLayout, 1200);
        }
      });
    } catch (e) { /* ignore */ }
  }

  function bindShareListeners() {
    if (shareListenersBound || typeof ZoomMtg === 'undefined') return;
    if (typeof ZoomMtg.inMeetingServiceListener !== 'function') return;
    shareListenersBound = true;

    try {
      ZoomMtg.inMeetingServiceListener('receiveSharingChannelReady', function () {
        applyPureShareLayout();
      });
    } catch (e) { /* ignore */ }

    try {
      ZoomMtg.inMeetingServiceListener('onShareContentChange', function () {
        if (isShareContentVisible()) {
          applyPureShareLayout();
        } else {
          clearShareLayout();
          refreshParticipantLayout();
        }
      });
    } catch (e) { /* ignore */ }

    try {
      ZoomMtg.inMeetingServiceListener('onMeetingStatus', function (data) {
        if (data && data.status === 3) {
          clearShareLayout();
          setParticipantCountClasses(0);
        }
      });
    } catch (e) { /* ignore */ }
  }

  function stopShareWatch() {
    if (dockTimer) {
      window.clearTimeout(dockTimer);
      dockTimer = null;
    }
    clearShareLayout();
    setParticipantCountClasses(0);
    shareListenersBound = false;
    participantListenersBound = false;
  }

  function showZoomRoot() {
    document.documentElement.classList.add('zoom-client-meeting-active');
    document.body.classList.add('zoom-client-meeting-active');
    var root = document.getElementById('zmmtg-root');
    if (root) root.style.display = 'block';
  }

  function getInitOptions(leaveUrl) {
    return {
      leaveUrl: leaveUrl,
      patchJsMedia: true,
      leaveOnPageUnload: true,
      showPureSharingContent: false,
      sharingMode: 'fit',
      defaultView: 'gallery',
      videoDrag: true,
      videoHeader: true,
      isLockBottom: true,
      disablePreview: true,
      enableHD: false,
      enableFullHD: false,
      isSupportPolling: false,
      isSupportQA: false,
      isSupportBreakout: false,
      isSupportSimulive: false
    };
  }

  function prepareSdk(zoomLibUrl) {
    if (preparePromise) return preparePromise;

    preparePromise = new Promise(function (resolve, reject) {
      try {
        if (typeof ZoomMtg.setZoomJSLib === 'function') {
          ZoomMtg.setZoomJSLib(zoomLibUrl, '/av');
        }
        ZoomMtg.preLoadWasm();
        var prep = ZoomMtg.prepareWebSDK();
        if (prep && typeof prep.then === 'function') {
          prep.then(resolve).catch(reject);
        } else {
          window.setTimeout(resolve, 300);
        }
      } catch (e) {
        preparePromise = null;
        reject(e);
      }
    });

    return preparePromise;
  }

  function initClient(leaveUrl) {
    if (initPromise) return initPromise;

    initPromise = new Promise(function (resolve, reject) {
      var opts = getInitOptions(leaveUrl);
      opts.success = function () {
        bindShareListeners();
        bindParticipantViewListeners();
        window.addEventListener('beforeunload', stopShareWatch, { once: true });
        resolve();
      };
      opts.error = function (err) {
        initPromise = null;
        reject(new Error(errMsg(err)));
      };
      ZoomMtg.init(opts);
    });

    return initPromise;
  }

  function fetchHostZak() {
    return fetch('fm_meeting_host_zak.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok && d.zak) return d.zak;
        return '';
      })
      .catch(function () { return ''; });
  }

  /**
   * Full boot flow: lazy assets → WASM → init → optional ZAK → join.
   * @param {object} cfg
   */
  function startMeeting(cfg) {
    var sdk = cfg.sdk;
    var leaveUrl = cfg.leaveUrl;
    var zoomLibUrl = cfg.zoomLibUrl;
    var assetBase = cfg.assetBase;
    var meetingJs = cfg.meetingJs;
    var zoomCssHref = cfg.zoomCssHref;
    var isHost = !!cfg.isHost;
    var onStatus = typeof cfg.onStatus === 'function' ? cfg.onStatus : function () {};
    var onJoined = typeof cfg.onJoined === 'function' ? cfg.onJoined : function () {};
    var onError = typeof cfg.onError === 'function' ? cfg.onError : function () {};

    if (!sdk || !sdk.signature) {
      onError('SDK credentials missing.');
      return Promise.reject(new Error('SDK credentials missing.'));
    }

    var joinStatusBound = false;
    function bindJoinStatusOnce(done) {
      if (joinStatusBound || typeof ZoomMtg === 'undefined') return;
      joinStatusBound = true;
      ZoomMtg.inMeetingServiceListener('onMeetingStatus', function (data) {
        if (data && data.status === 2) done();
      });
    }

    function doJoin(passWord, useZak) {
      return new Promise(function (resolve, reject) {
        var finished = false;
        function finish(ok, val) {
          if (finished) return;
          finished = true;
          ok ? resolve(val) : reject(val);
        }
        var timer = window.setTimeout(function () {
          finish(false, new Error('Join timed out. Close other tabs and try again.'));
        }, 75000);

        bindJoinStatusOnce(function () {
          window.clearTimeout(timer);
          finish(true);
        });

        var payload = {
          signature: sdk.signature,
          meetingNumber: String(sdk.meeting_number),
          userName: sdk.user_name || (isHost ? 'Host' : 'Guest'),
          passWord: passWord,
          success: function () {
            window.setTimeout(function () {
              window.clearTimeout(timer);
              finish(true);
            }, 800);
          },
          error: function (err) {
            window.clearTimeout(timer);
            finish(false, new Error(errMsg(err)));
          }
        };
        if (sdk.user_email) payload.userEmail = sdk.user_email;
        if (useZak && sdk.zak) payload.zak = sdk.zak;
        ZoomMtg.join(payload);
      });
    }

    function joinMeeting() {
      var pass = sdk.password || '';
      var tries = isHost
        ? [{ p: pass, z: true }, { p: pass, z: false }, { p: '', z: false }]
        : [{ p: pass }, { p: '' }];
      var i = 0;

      function next(err) {
        if (i >= tries.length) {
          var msg = err && err.message ? err.message : 'Join failed';
          onError(msg);
          return Promise.reject(err || new Error(msg));
        }
        var t = tries[i++];
        onStatus('Joining meeting…');
        return doJoin(t.p, !!t.z).catch(next);
      }

      return next();
    }

    onStatus('Loading Zoom components…');

    return loadZoomAssets(assetBase, meetingJs, zoomCssHref)
      .then(function () {
        onStatus('Preparing audio/video…');
        return prepareSdk(zoomLibUrl);
      })
      .then(function () {
        onStatus('Initializing meeting room…');
        showZoomRoot();
        return initClient(leaveUrl);
      })
      .then(function () {
        if (isHost && !sdk.zak) {
          onStatus('Connecting as host…');
          return fetchHostZak().then(function (zak) {
            if (zak) sdk.zak = zak;
          });
        }
      })
      .then(function () {
        return joinMeeting();
      })
      .then(function () {
        onJoined();
        window.setTimeout(refreshParticipantLayout, 1500);
      })
      .catch(function (e) {
        onError(e && e.message ? e.message : String(e));
        throw e;
      });
  }

  global.FmZoomRoom = {
    errMsg: errMsg,
    loadZoomAssets: loadZoomAssets,
    prepareSdk: prepareSdk,
    initClient: initClient,
    startMeeting: startMeeting,
    stopShareWatch: stopShareWatch,
    applyPureShareLayout: applyPureShareLayout,
    refreshParticipantLayout: refreshParticipantLayout,
    showZoomRoot: showZoomRoot
  };
})(window);
