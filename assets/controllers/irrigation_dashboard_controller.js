import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = [
        "parcelleSelect",
        "modeBadge",
        "pumpBadge",
        "soilValue",
        "needsWaterBadge",
        "updatedAt",
        "eventsBody",
        "eventsSearch",
        "eventsSourceFilter",
        "eventsDryOnly",
        "eventsCountLabel",
        "healthBadge",
        "statEventsTotal",
        "statAutoActions",
        "statDryRate",
        "statSoilAverage",
        "notificationsBody",
        "commandButton",
        "alert",
    ];

    static values = {
        baseUrl: String,
        csrfToken: String,
        initialState: Object,
        initialEvents: Array,
        pollInterval: { type: Number, default: 3000 },
    };

    connect() {
        this.pollHandle = null;
        this.alertTimeoutHandle = null;
        this.refreshInFlight = false;
        this.currentParcelleId = this.getSelectedParcelleId();
        this.latestState = null;
        this.latestEvents = [];

        if (this.hasInitialStateValue && this.initialStateValue) {
            this.latestState = this.initialStateValue;
        }

        if (this.hasInitialEventsValue && Array.isArray(this.initialEventsValue)) {
            this.latestEvents = this.initialEventsValue;
        }

        if (this.currentParcelleId > 0) {
            this.renderState(this.latestState);
            this.renderEvents(this.latestEvents);
            this.renderInsights(this.latestState, this.latestEvents);
            this.setButtonsDisabled(false);
            this.refreshAll();
            this.startPolling();
            return;
        }

        this.setButtonsDisabled(true);
        this.renderEmptyState();
        this.renderEvents([]);
        this.renderInsights(null, []);
    }

    disconnect() {
        this.stopPolling();
        if (this.alertTimeoutHandle !== null) {
            window.clearTimeout(this.alertTimeoutHandle);
        }
    }

    changeParcelle() {
        this.currentParcelleId = this.getSelectedParcelleId();
        this.syncUrlQuery();
        this.latestState = null;
        this.latestEvents = [];

        this.stopPolling();

        if (this.currentParcelleId <= 0) {
            this.setButtonsDisabled(true);
            this.renderEmptyState();
            this.renderEvents([]);
            this.renderInsights(null, []);
            return;
        }

        this.setButtonsDisabled(false);
        this.refreshAll();
        this.startPolling();
    }

    async sendCommand(event) {
        event.preventDefault();

        if (this.currentParcelleId <= 0) {
            this.showAlert("Select a plot first.", "warning");
            return;
        }

        const button = event.currentTarget;
        const command = String(button.dataset.command || "").trim().toUpperCase();

        if (!["AUTO", "MANUAL_ON", "MANUAL_OFF"].includes(command)) {
            this.showAlert("Unknown command.", "danger");
            return;
        }

        this.setButtonsDisabled(true);

        try {
            const response = await fetch(this.buildEndpoint("command"), {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                    "X-CSRF-TOKEN": this.csrfTokenValue,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({
                    command,
                    _token: this.csrfTokenValue,
                }),
            });

            const payload = await this.readJson(response);

            if (!response.ok) {
                throw new Error(payload.error || "Command could not be sent.");
            }

            this.showAlert("Command sent.", "success");
            await this.refreshAll(true);
        } catch (error) {
            this.showAlert(this.toMessage(error), "danger");
        } finally {
            this.setButtonsDisabled(false);
        }
    }

    startPolling() {
        this.stopPolling();

        const interval = this.pollIntervalValue > 0 ? this.pollIntervalValue : 3000;
        this.pollHandle = window.setInterval(() => {
            this.refreshAll(true);
        }, interval);
    }

    stopPolling() {
        if (this.pollHandle !== null) {
            window.clearInterval(this.pollHandle);
            this.pollHandle = null;
        }
    }

    async refreshAll(silent = false) {
        if (this.currentParcelleId <= 0 || this.refreshInFlight) {
            return;
        }

        this.refreshInFlight = true;

        try {
            const [stateResponse, eventsResponse] = await Promise.all([
                fetch(this.buildEndpoint("state"), {
                    headers: {
                        "Accept": "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                }),
                fetch(this.buildEndpoint("events"), {
                    headers: {
                        "Accept": "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                }),
            ]);

            const statePayload = await this.readJson(stateResponse);
            const eventsPayload = await this.readJson(eventsResponse);

            if (!stateResponse.ok) {
                throw new Error(statePayload.error || "Failed to load state.");
            }

            if (!eventsResponse.ok) {
                throw new Error(eventsPayload.error || "Failed to load events.");
            }

            this.latestState = statePayload.state || null;
            this.latestEvents = Array.isArray(eventsPayload.events) ? eventsPayload.events : [];

            this.renderState(this.latestState);
            this.renderEvents(this.latestEvents);
            this.renderInsights(this.latestState, this.latestEvents);

            if (!silent) {
                this.clearAlert();
            }
        } catch (error) {
            if (!silent) {
                this.showAlert(this.toMessage(error), "danger");
            }
        } finally {
            this.refreshInFlight = false;
        }
    }

    renderState(state) {
        if (!state || state.exists !== true) {
            this.renderEmptyState();
            return;
        }

        const mode = String(state.mode || "N/A").toUpperCase();
        this.setBadge(this.modeBadgeTarget, mode, this.modeBadgeClass(mode));

        const pumpRunning = this.toNullableBoolean(state.pumpRunning);
        if (pumpRunning === true) {
            this.setBadge(this.pumpBadgeTarget, "ON", "text-bg-success");
        } else if (pumpRunning === false) {
            this.setBadge(this.pumpBadgeTarget, "OFF", "text-bg-secondary");
        } else {
            this.setBadge(this.pumpBadgeTarget, "N/A", "text-bg-secondary");
        }

        const soilCondition = this.getSoilCondition(state.soilValue, state.needsWater);
        this.setBadge(this.soilValueTarget, soilCondition.label, soilCondition.badgeClass);

        const needsWater = this.toNullableBoolean(state.needsWater);
        if (needsWater === true) {
            this.setBadge(this.needsWaterBadgeTarget, "REQUIRED", "text-bg-warning");
        } else if (needsWater === false) {
            this.setBadge(this.needsWaterBadgeTarget, "NOT REQUIRED", "text-bg-success");
        } else {
            this.setBadge(this.needsWaterBadgeTarget, "N/A", "text-bg-secondary");
        }

        this.updatedAtTarget.textContent = this.formatDateTime(state.updatedAt);
    }

    renderEmptyState() {
        this.setBadge(this.modeBadgeTarget, "N/A", "text-bg-secondary");
        this.setBadge(this.pumpBadgeTarget, "N/A", "text-bg-secondary");
        this.setBadge(this.soilValueTarget, "UNKNOWN", "text-bg-secondary");
        this.setBadge(this.needsWaterBadgeTarget, "N/A", "text-bg-secondary");
        this.updatedAtTarget.textContent = "N/A";

        if (this.hasEventsCountLabelTarget) {
            this.eventsCountLabelTarget.textContent = "0";
        }

        if (this.hasHealthBadgeTarget) {
            this.setBadge(this.healthBadgeTarget, "NO DATA", "text-bg-secondary");
        }
    }

    renderEvents(events) {
        if (!this.hasEventsBodyTarget) {
            return;
        }

        const sourceEvents = Array.isArray(events) ? events : [];
        const filteredEvents = this.filterEventsData(sourceEvents);

        if (this.hasEventsCountLabelTarget) {
            this.eventsCountLabelTarget.textContent = String(filteredEvents.length);
        }

        if (filteredEvents.length === 0) {
            this.eventsBodyTarget.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No events match the active filters.</td>
                </tr>
            `;
            return;
        }

        this.eventsBodyTarget.innerHTML = filteredEvents
            .map((eventItem) => this.renderEventRow(eventItem))
            .join("");
    }

    renderEventRow(eventItem) {
        const soilCondition = this.getSoilCondition(eventItem.soilValue, eventItem.needsWater);
        const demand = this.getDemandStatus(eventItem.needsWater);

        return `
            <tr>
                <td>${this.escapeHtml(this.formatDateTime(eventItem.createdAt))}</td>
                <td>${this.escapeHtml(eventItem.source || "-")}</td>
                <td>${this.escapeHtml(eventItem.eventType || "-")}</td>
                <td>${this.escapeHtml(eventItem.message || "-")}</td>
                <td><span class="badge rounded-pill ${soilCondition.badgeClass}">${this.escapeHtml(soilCondition.label)}</span></td>
                <td><span class="badge rounded-pill ${demand.badgeClass}">${this.escapeHtml(demand.label)}</span></td>
            </tr>
        `;
    }

    renderInsights(state, events) {
        const safeEvents = Array.isArray(events) ? events : [];
        const totalEvents = safeEvents.length;
        const autoActions = safeEvents.filter((eventItem) => this.isAutoEvent(eventItem)).length;
        const dryEvents = safeEvents.filter((eventItem) => this.getSoilCondition(eventItem.soilValue, eventItem.needsWater).label === "DRY").length;
        const dryRate = totalEvents > 0 ? Math.round((dryEvents / totalEvents) * 100) : 0;

        const soilValues = safeEvents
            .map((eventItem) => this.toNumber(eventItem.soilValue))
            .filter((value) => value !== null);

        const soilAverage = soilValues.length > 0
            ? (soilValues.reduce((accumulator, value) => accumulator + value, 0) / soilValues.length)
            : null;

        if (this.hasStatEventsTotalTarget) {
            this.statEventsTotalTarget.textContent = String(totalEvents);
        }

        if (this.hasStatAutoActionsTarget) {
            this.statAutoActionsTarget.textContent = String(autoActions);
        }

        if (this.hasStatDryRateTarget) {
            this.statDryRateTarget.textContent = `${dryRate}%`;
        }

        if (this.hasStatSoilAverageTarget) {
            this.statSoilAverageTarget.textContent = soilAverage !== null ? soilAverage.toFixed(2) : "N/A";
        }

        this.renderNotifications(state, safeEvents, {
            totalEvents,
            autoActions,
            dryRate,
            soilAverage,
        });
    }

    changeEventsFilters() {
        this.renderEvents(this.latestEvents);
    }

    filterEventsData(events) {
        let filtered = [...events];

        if (this.hasEventsSourceFilterTarget) {
            const selectedSource = String(this.eventsSourceFilterTarget.value || "").trim().toUpperCase();
            if (selectedSource !== "") {
                filtered = filtered.filter((eventItem) => String(eventItem.source || "").toUpperCase() === selectedSource);
            }
        }

        if (this.hasEventsDryOnlyTarget && this.eventsDryOnlyTarget.checked) {
            filtered = filtered.filter((eventItem) => this.getSoilCondition(eventItem.soilValue, eventItem.needsWater).label === "DRY");
        }

        if (this.hasEventsSearchTarget) {
            const query = String(this.eventsSearchTarget.value || "").trim().toLowerCase();
            if (query !== "") {
                filtered = filtered.filter((eventItem) => this.matchesEventSearch(eventItem, query));
            }
        }

        return filtered;
    }

    matchesEventSearch(eventItem, query) {
        const haystack = [
            eventItem.source,
            eventItem.eventType,
            eventItem.message,
            this.formatDateTime(eventItem.createdAt),
            this.getSoilCondition(eventItem.soilValue, eventItem.needsWater).label,
            this.getDemandStatus(eventItem.needsWater).label,
        ]
            .map((value) => String(value || "").toLowerCase())
            .join(" ");

        return haystack.includes(query);
    }

    getSoilCondition(soilValue, needsWaterValue) {
        const numericValue = this.toNumber(soilValue);
        const needsWater = this.toNullableBoolean(needsWaterValue);

        if (numericValue === 0 || needsWater === true) {
            return { label: "DRY", badgeClass: "text-bg-warning" };
        }

        if (numericValue === 1 || needsWater === false) {
            return { label: "WET", badgeClass: "text-bg-info" };
        }

        return { label: "UNKNOWN", badgeClass: "text-bg-secondary" };
    }

    getDemandStatus(needsWaterValue) {
        const needsWater = this.toNullableBoolean(needsWaterValue);

        if (needsWater === true) {
            return { label: "REQUIRED", badgeClass: "text-bg-warning" };
        }

        if (needsWater === false) {
            return { label: "NOT REQUIRED", badgeClass: "text-bg-success" };
        }

        return { label: "N/A", badgeClass: "text-bg-secondary" };
    }

    renderNotifications(state, events, metrics) {
        if (!this.hasNotificationsBodyTarget) {
            return;
        }

        const notifications = [];

        if (!state || state.exists !== true) {
            notifications.push({
                level: "info",
                title: "No state recorded",
                message: "The system is waiting for the first Raspberry Pi sync.",
            });

            this.renderHealthBadge("NO DATA", "text-bg-secondary");
            this.notificationsBodyTarget.innerHTML = notifications.map((item) => this.renderNotificationItem(item)).join("");
            return;
        }

        const stateAgeSeconds = this.secondsSince(state.updatedAt);
        const pumpRunning = this.toNullableBoolean(state.pumpRunning) === true;
        const needsWater = this.toNullableBoolean(state.needsWater);
        const mode = String(state.mode || "").toUpperCase();

        if (stateAgeSeconds !== null && stateAgeSeconds > 180) {
            notifications.push({
                level: "warning",
                title: "Potentially stale data",
                message: `Last update received ${Math.round(stateAgeSeconds)} seconds ago.`,
            });
        } else {
            notifications.push({
                level: "success",
                title: "Real-time stream active",
                message: "Sensors and events are syncing normally.",
            });
        }

        if (mode === "AUTO" && needsWater === true && !pumpRunning) {
            notifications.push({
                level: "warning",
                title: "Dry plot detected",
                message: "AUTO mode indicates water demand while the pump is OFF.",
            });
        }

        if (metrics.dryRate >= 70) {
            notifications.push({
                level: "warning",
                title: "High water stress",
                message: `${metrics.dryRate}% of recent events indicate water demand.`,
            });
        }

        if (metrics.autoActions > 0) {
            const latestAutoEvent = events.find((eventItem) => this.isAutoEvent(eventItem));
            notifications.push({
                level: "info",
                title: "AUTO activity detected",
                message: latestAutoEvent
                    ? `Latest AUTO action: ${String(latestAutoEvent.eventType || "N/A")} (${this.formatDateTime(latestAutoEvent.createdAt)}).`
                    : "AUTO actions were detected in recent events.",
            });
        }

        if (mode.startsWith("MANUAL") && pumpRunning) {
            notifications.push({
                level: "info",
                title: "Manual mode active",
                message: "The pump is running in manual mode. Check activation duration.",
            });
        }

        if (notifications.length === 0) {
            notifications.push({
                level: "info",
                title: "Neutral alert",
                message: "No specific alert on the latest measurements.",
            });
        }

        if (notifications.some((item) => item.level === "warning")) {
            this.renderHealthBadge("WATCH", "text-bg-warning");
        } else if (notifications.some((item) => item.level === "danger")) {
            this.renderHealthBadge("CRITICAL", "text-bg-danger");
        } else {
            this.renderHealthBadge("STABLE", "text-bg-success");
        }

        this.notificationsBodyTarget.innerHTML = notifications
            .slice(0, 5)
            .map((item) => this.renderNotificationItem(item))
            .join("");
    }

    renderHealthBadge(text, badgeClass) {
        if (!this.hasHealthBadgeTarget) {
            return;
        }

        this.setBadge(this.healthBadgeTarget, text, badgeClass);
    }

    renderNotificationItem(item) {
        const levelClassMap = {
            success: "irrigation-note-success",
            warning: "irrigation-note-warning",
            danger: "irrigation-note-danger",
            info: "irrigation-note-info",
        };

        const iconMap = {
            success: "check_circle",
            warning: "warning",
            danger: "error",
            info: "info",
        };

        const level = ["success", "warning", "danger", "info"].includes(item.level) ? item.level : "info";
        const cssClass = levelClassMap[level];
        const icon = iconMap[level];

        return `
            <li class="irrigation-note-item ${cssClass}">
                <span class="material-symbols-outlined irrigation-note-icon">${icon}</span>
                <div class="irrigation-note-content">
                    <p class="irrigation-note-title">${this.escapeHtml(item.title || "Information")}</p>
                    <p class="irrigation-note-message">${this.escapeHtml(item.message || "")}</p>
                </div>
            </li>
        `;
    }

    isAutoEvent(eventItem) {
        const source = String(eventItem && eventItem.source ? eventItem.source : "").toUpperCase();
        const eventType = String(eventItem && eventItem.eventType ? eventItem.eventType : "").toUpperCase();

        return source === "AUTO" || eventType.includes("_AUTO") || eventType.includes("AUTO_");
    }

    toNumber(value) {
        if (value === null || value === undefined || value === "") {
            return null;
        }

        const parsed = Number(value);

        return Number.isFinite(parsed) ? parsed : null;
    }

    secondsSince(value) {
        if (!value) {
            return null;
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return null;
        }

        return Math.max(0, (Date.now() - date.getTime()) / 1000);
    }

    setButtonsDisabled(disabled) {
        if (!this.hasCommandButtonTarget) {
            return;
        }

        this.commandButtonTargets.forEach((button) => {
            button.disabled = disabled;
            button.classList.toggle("disabled", disabled);
        });
    }

    setBadge(element, text, contextualClass) {
        element.className = `badge rounded-pill ${contextualClass}`;
        element.textContent = text;
    }

    modeBadgeClass(mode) {
        if (mode === "AUTO") {
            return "text-bg-primary";
        }

        if (mode === "MANUAL_ON" || mode === "MANUAL_OFF") {
            return "text-bg-dark";
        }

        return "text-bg-secondary";
    }

    syncUrlQuery() {
        const url = new URL(window.location.href);

        if (this.currentParcelleId > 0) {
            url.searchParams.set("parcelle", String(this.currentParcelleId));
        } else {
            url.searchParams.delete("parcelle");
        }

        window.history.replaceState({}, "", url);
    }

    getSelectedParcelleId() {
        if (!this.hasParcelleSelectTarget) {
            return 0;
        }

        const parsed = Number.parseInt(this.parcelleSelectTarget.value, 10);

        return Number.isNaN(parsed) ? 0 : parsed;
    }

    buildEndpoint(action) {
        const baseUrl = this.baseUrlValue.endsWith("/")
            ? this.baseUrlValue.slice(0, -1)
            : this.baseUrlValue;

        return `${baseUrl}/${this.currentParcelleId}/${action}`;
    }

    async readJson(response) {
        try {
            return await response.json();
        } catch {
            return {};
        }
    }

    formatDateTime(value) {
        if (!value) {
            return "N/A";
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return new Intl.DateTimeFormat("en-GB", {
            dateStyle: "short",
            timeStyle: "medium",
        }).format(date);
    }

    toNullableBoolean(value) {
        if (value === true || value === 1 || value === "1") {
            return true;
        }

        if (value === false || value === 0 || value === "0") {
            return false;
        }

        return null;
    }

    showAlert(message, level) {
        if (!this.hasAlertTarget) {
            return;
        }

        const safeLevel = ["success", "danger", "warning", "info"].includes(level) ? level : "info";

        this.alertTarget.className = `alert alert-${safeLevel}`;
        this.alertTarget.textContent = message;

        if (this.alertTimeoutHandle !== null) {
            window.clearTimeout(this.alertTimeoutHandle);
        }

        if (safeLevel === "success") {
            this.alertTimeoutHandle = window.setTimeout(() => {
                this.clearAlert();
            }, 2400);
        }
    }

    clearAlert() {
        if (!this.hasAlertTarget) {
            return;
        }

        this.alertTarget.className = "alert d-none";
        this.alertTarget.textContent = "";
    }

    toMessage(error) {
        if (error instanceof Error && error.message.trim() !== "") {
            return error.message;
        }

        return "A network error occurred.";
    }

    escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }
}
