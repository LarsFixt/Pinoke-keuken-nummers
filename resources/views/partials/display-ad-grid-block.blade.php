<script>
    (function registerDisplayAdGridBlock() {
        if (window.displayAdGridBlock) {
            return;
        }

        window.displayAdGridBlock = function displayAdGridBlock(config) {
            return {
                ads: [],
                visibleAds: [],
                activeAdIndex: 0,
                rotationTimer: null,
                adsRefreshTimer: null,
                columns: 5,
                adsEndpoint: config?.adsEndpoint ?? '',
                concurrentSponsors: Math.max(parseInt(config?.concurrentSponsors ?? 1, 10), 1),

                init() {
                    this.columns = this.resolveColumns();
                    this.buildVisibleAds();
                    this.fetchAds();

                    this.adsRefreshTimer = window.setInterval(() => {
                        this.fetchAds();
                    }, 60000);
                },

                destroyTimers() {
                    if (this.rotationTimer) {
                        window.clearTimeout(this.rotationTimer);
                        this.rotationTimer = null;
                    }

                    if (this.adsRefreshTimer) {
                        window.clearInterval(this.adsRefreshTimer);
                        this.adsRefreshTimer = null;
                    }
                },

                playSound() {
                    new Audio('/sound/bell.mp3').play();
                },

                resolveColumns() {
                    if (window.innerWidth >= 1024) {
                        return 5;
                    }

                    if (window.innerWidth >= 768) {
                        return 3;
                    }

                    return 2;
                },

                handleResize() {
                    var nextColumns = this.resolveColumns();

                    if (nextColumns !== this.columns) {
                        this.columns = nextColumns;
                    }
                },

                buildVisibleAds() {
                    if (!this.ads.length) {
                        this.visibleAds = [];

                        return;
                    }

                    var count = Math.min(this.concurrentSponsors, this.ads.length);
                    var ads = [];

                    for (var offset = 0; offset < count; offset += 1) {
                        var index = (this.activeAdIndex + offset) % this.ads.length;
                        var ad = this.ads[index];

                        if (ad) {
                            ads.push(ad);
                        }
                    }

                    this.visibleAds = ads;
                },

                scheduleRotation() {
                    if (this.rotationTimer) {
                        window.clearTimeout(this.rotationTimer);
                        this.rotationTimer = null;
                    }

                    if (!this.visibleAds.length) {
                        return;
                    }

                    var activeAd = this.visibleAds[0];
                    var durationSeconds = Math.max(parseInt(activeAd?.duration_seconds ?? 10, 10), 1);

                    this.rotationTimer = window.setTimeout(() => {
                        if (!this.ads.length) {
                            return;
                        }

                        this.activeAdIndex = (this.activeAdIndex + this.concurrentSponsors) % this.ads
                            .length;
                        this.buildVisibleAds();
                        this.scheduleRotation();
                    }, durationSeconds * 1000);
                },

                async fetchAds() {
                    try {
                        var response = await fetch(this.adsEndpoint, {
                            headers: {
                                Accept: 'application/json',
                            },
                        });

                        if (!response.ok) {
                            throw new Error('Ad playlist request failed');
                        }

                        var payload = await response.json();

                        this.ads = Array.isArray(payload) ? payload : [];

                        if (this.activeAdIndex >= this.ads.length) {
                            this.activeAdIndex = 0;
                        }

                        this.buildVisibleAds();
                        this.scheduleRotation();
                    } catch (error) {
                        this.ads = [];
                        this.buildVisibleAds();
                        this.scheduleRotation();
                    }
                },
            };
        };
    })();
</script>
