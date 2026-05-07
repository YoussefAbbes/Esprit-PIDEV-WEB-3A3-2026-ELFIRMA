import com.sun.net.httpserver.HttpServer;
import com.sun.net.httpserver.HttpHandler;
import com.sun.net.httpserver.HttpExchange;
import com.zkteco.biometric.ZKFPService;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Executors;
import java.util.logging.Logger;

/**
 * FingerprintBridgeServer
 * ========================
 * Lightweight HTTP bridge wrapping the ZKFinger SDK (ZKFPService native API).
 * Exposes REST endpoints on http://localhost:8085 so a Symfony PHP app can
 * drive fingerprint enrollment, verification and identification.
 *
 * Real ZKFPService API (discovered via javap):
 *
 *   ZKFPService.Initialize()                          → int  (0 = ok)
 *   ZKFPService.Finalize()                            → int
 *   ZKFPService.GetDeviceCount()                      → int
 *   ZKFPService.OpenDevice(int index)                 → long handle
 *   ZKFPService.CloseDevice(long handle)              → int
 *   ZKFPService.GetCapParams(long, int[], int[])      → int  (fills width, height)
 *   ZKFPService.AcquireTemplate(long, byte[], byte[], int[]) → int (imgbuf, tpl, tplLen)
 *   ZKFPService.DBInit()                              → long dbHandle
 *   ZKFPService.DBFree(long)                          → int
 *   ZKFPService.DBAdd(int fid, byte[] tpl)            → int  (global DB)
 *   ZKFPService.DBDel(int fid)                        → int
 *   ZKFPService.DBClear()                             → int
 *   ZKFPService.DBCount()                             → int
 *   ZKFPService.MatchFP(byte[] t1, byte[] t2)         → int  (>0 = match, score)
 *   ZKFPService.IdentifyFP(byte[], int[], int[])      → int  (0 = found, fills fid, score)
 *   ZKFPService.GenRegFPTemplate(byte[],byte[],byte[],byte[],int[]) → int (merge 3 → 1)
 *   ZKFPService.BlobToBase64(byte[], int)             → String
 *   ZKFPService.Base64ToBlob(String, byte[], int)     → int
 *
 * NOTE: DBAdd/DBDel/IdentifyFP operate on a GLOBAL in-process DB managed by
 *       the native library (no explicit db handle).  We therefore load every
 *       enrolled template into this global DB before each identify call and
 *       clear it afterwards to keep state clean.
 *
 * Endpoints (all POST, JSON in / JSON out):
 *   POST /status          → device info
 *   POST /enroll/start    → begin 3-scan enrollment session
 *   POST /enroll/capture  → capture one scan (call 3 times)
 *   POST /verify          → 1:1 live capture vs supplied base64 template
 *   POST /identify        → 1:N live capture vs list of {user_id, template, template_length}
 */
public class FingerprintBridgeServer {

    // ── Constants ────────────────────────────────────────────────────────────
    private static final int    PORT            = 8085;
    private static final int    TEMPLATE_SIZE   = 2048;
    private static final int    ENROLL_STEPS    = 3;
    private static final long   ACQUIRE_TIMEOUT = 12_000;   // ms
    private static final long   ACQUIRE_POLL    = 300;      // ms

    private static final Logger LOG = Logger.getLogger(FingerprintBridgeServer.class.getName());

    // ── SDK state (all access under synchronized(this)) ──────────────────────
    private long    deviceHandle   = 0;
    private int     fpWidth        = 0;
    private int     fpHeight       = 0;
    private boolean sdkInitialized = false;
    private boolean deviceOpen     = false;

    // ── Enrollment state machine ──────────────────────────────────────────────
    private int      enrollStep    = 0;
    private byte[][] enrollBuffers = new byte[ENROLL_STEPS][TEMPLATE_SIZE];
    // Actual valid lengths for each captured scan (may be < TEMPLATE_SIZE)
    private int[]    enrollLengths = new int[ENROLL_STEPS];

    // ── Image buffer (allocated once after device open) ───────────────────────
    private byte[] imgBuf = null;

    // =========================================================================
    // main
    // =========================================================================
    public static void main(String[] args) throws IOException {
        new FingerprintBridgeServer().start();
    }

    // =========================================================================
    // Lifecycle
    // =========================================================================
    private void start() throws IOException {
        initSdk();

        HttpServer server = HttpServer.create(new InetSocketAddress("0.0.0.0", PORT), 64);
        server.createContext("/status",         new StatusHandler());
        server.createContext("/enroll/start",   new EnrollStartHandler());
        server.createContext("/enroll/capture", new EnrollCaptureHandler());
        server.createContext("/verify",         new VerifyHandler());
        server.createContext("/identify",       new IdentifyHandler());
        server.setExecutor(Executors.newFixedThreadPool(4));
        server.start();

        LOG.info("===========================================================");
        LOG.info("  ZKFinger HTTP Bridge  →  http://localhost:" + PORT);
        LOG.info("  SDK initialised : " + sdkInitialized);
        LOG.info("  Device open     : " + deviceOpen);
        LOG.info("===========================================================");

        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            teardownSdk();
            server.stop(2);
            LOG.info("Bridge stopped.");
        }));
    }

    // =========================================================================
    // SDK helpers
    // =========================================================================
    private synchronized void initSdk() {
        int ret = ZKFPService.Initialize();
        if (ret != 0) {
            LOG.warning("ZKFPService.Initialize() returned " + ret + " – SDK init failed.");
            return;
        }
        sdkInitialized = true;
        LOG.info("ZKFPService.Initialize() OK");

        int count = ZKFPService.GetDeviceCount();
        LOG.info("Devices found: " + count);
        if (count <= 0) {
            LOG.warning("No fingerprint device detected (count=" + count + "). Endpoints will still serve but capture will fail.");
            return;
        }

        deviceHandle = ZKFPService.OpenDevice(0);
        if (deviceHandle == 0) {
            LOG.warning("OpenDevice(0) returned 0 – could not open device.");
            return;
        }
        deviceOpen = true;
        LOG.info("Device opened, handle=" + deviceHandle);

        // Read image dimensions
        int[] w = new int[1];
        int[] h = new int[1];
        int gret = ZKFPService.GetCapParams(deviceHandle, w, h);
        if (gret == 0) {
            fpWidth  = w[0];
            fpHeight = h[0];
        } else {
            fpWidth  = 320;
            fpHeight = 240;
            LOG.warning("GetCapParams returned " + gret + ", using defaults " + fpWidth + "x" + fpHeight);
        }
        imgBuf = new byte[fpWidth * fpHeight];
        LOG.info("Image size: " + fpWidth + "x" + fpHeight);
    }

    private synchronized void teardownSdk() {
        if (deviceOpen && deviceHandle != 0) {
            ZKFPService.CloseDevice(deviceHandle);
            deviceOpen   = false;
            deviceHandle = 0;
        }
        if (sdkInitialized) {
            ZKFPService.Finalize();
            sdkInitialized = false;
        }
    }

    /** Try to re-open device if it was disconnected. */
    private synchronized boolean ensureDevice() {
        if (deviceOpen && deviceHandle != 0) return true;
        if (!sdkInitialized) return false;
        int count = ZKFPService.GetDeviceCount();
        if (count <= 0) return false;
        deviceHandle = ZKFPService.OpenDevice(0);
        if (deviceHandle == 0) return false;
        deviceOpen = true;
        if (imgBuf == null) imgBuf = new byte[Math.max(fpWidth * fpHeight, 320 * 240)];
        return true;
    }

    /**
     * Block until a fingerprint template is acquired or timeout elapses.
     * Returns a full TEMPLATE_SIZE (2048-byte) zero-padded buffer on success
     * so it can be passed directly to DBAdd / GenRegFPTemplate.
     * tplLenOut[0] is set to the actual valid byte count returned by the SDK.
     */
    private synchronized byte[] acquireWithTimeout(int[] tplLenOut) {
        if (!ensureDevice()) return null;

        long deadline = System.currentTimeMillis() + ACQUIRE_TIMEOUT;
        // Always allocate a full 2048-byte buffer – SDK writes valid data into
        // the first tplLen bytes; the remaining bytes stay zero (harmless padding).
        byte[] tpl    = new byte[TEMPLATE_SIZE];
        int[]  tplLen = new int[]{TEMPLATE_SIZE};

        while (System.currentTimeMillis() < deadline) {
            // Reset length hint before each call
            tplLen[0] = TEMPLATE_SIZE;
            int ret = ZKFPService.AcquireTemplate(deviceHandle, imgBuf, tpl, tplLen);
            if (ret == 0 && tplLen[0] > 0) {
                tplLenOut[0] = tplLen[0];
                // Return the full 2048-byte buffer (zero-padded after tplLen[0])
                return tpl;
            }
            try { Thread.sleep(ACQUIRE_POLL); } catch (InterruptedException e) { Thread.currentThread().interrupt(); return null; }
        }
        return null;  // timeout
    }

    // =========================================================================
    // HTTP utilities
    // =========================================================================
    private static void sendJson(HttpExchange ex, int status, String json) throws IOException {
        byte[] body = json.getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().set("Content-Type", "application/json; charset=utf-8");
        ex.getResponseHeaders().set("Access-Control-Allow-Origin", "*");
        ex.getResponseHeaders().set("Access-Control-Allow-Methods", "POST, OPTIONS");
        ex.getResponseHeaders().set("Access-Control-Allow-Headers", "Content-Type");
        ex.sendResponseHeaders(status, body.length);
        try (OutputStream os = ex.getResponseBody()) { os.write(body); }
    }

    private static String readBody(HttpExchange ex) throws IOException {
        try (InputStream is = ex.getRequestBody();
             ByteArrayOutputStream buf = new ByteArrayOutputStream()) {
            byte[] chunk = new byte[4096];
            int n;
            while ((n = is.read(chunk)) != -1) {
                buf.write(chunk, 0, n);
            }
            return buf.toString("UTF-8");
        }
    }

    /** Handle CORS pre-flight and return false for non-POST; true to continue. */
    private static boolean handlePreflight(HttpExchange ex) throws IOException {
        if ("OPTIONS".equalsIgnoreCase(ex.getRequestMethod())) {
            ex.getResponseHeaders().set("Access-Control-Allow-Origin", "*");
            ex.getResponseHeaders().set("Access-Control-Allow-Methods", "POST, OPTIONS");
            ex.getResponseHeaders().set("Access-Control-Allow-Headers", "Content-Type");
            ex.sendResponseHeaders(204, -1);
            return false;
        }
        return true;
    }

    // ─── Minimal JSON helpers (no external deps) ─────────────────────────────

    /** Extract string value for key from flat JSON (no nesting supported for values). */
    private static String jsonString(String json, String key) {
        String search = "\"" + key + "\"";
        int ki = json.indexOf(search);
        if (ki < 0) return null;
        int colon = json.indexOf(':', ki + search.length());
        if (colon < 0) return null;
        int vs = colon + 1;
        while (vs < json.length() && Character.isWhitespace(json.charAt(vs))) vs++;
        if (vs >= json.length()) return null;
        char fc = json.charAt(vs);
        if (fc == '"') {
            StringBuilder sb = new StringBuilder();
            boolean escaping = false;

            for (int i = vs + 1; i < json.length(); i++) {
                char c = json.charAt(i);

                if (escaping) {
                    switch (c) {
                        case '"': sb.append('"'); break;
                        case '\\': sb.append('\\'); break;
                        case '/': sb.append('/'); break;
                        case 'b': sb.append('\b'); break;
                        case 'f': sb.append('\f'); break;
                        case 'n': sb.append('\n'); break;
                        case 'r': sb.append('\r'); break;
                        case 't': sb.append('\t'); break;
                        case 'u':
                            if (i + 4 < json.length()) {
                                String hex = json.substring(i + 1, i + 5);
                                try {
                                    sb.append((char) Integer.parseInt(hex, 16));
                                    i += 4;
                                } catch (NumberFormatException ex) {
                                    return null;
                                }
                            } else {
                                return null;
                            }
                            break;
                        default:
                            sb.append(c);
                    }
                    escaping = false;
                    continue;
                }

                if (c == '\\') {
                    escaping = true;
                    continue;
                }

                if (c == '"') {
                    return sb.toString();
                }

                sb.append(c);
            }

            return null;
        }
        // number / bool / null
        int end = vs;
        while (end < json.length() && ",}\n\r ".indexOf(json.charAt(end)) < 0) end++;
        return json.substring(vs, end).trim();
    }

    private static Integer jsonInt(String json, String key) {
        String v = jsonString(json, key);
        if (v == null) return null;
        try { return Integer.parseInt(v); } catch (NumberFormatException e) { return null; }
    }

    /**
     * Decodes a base64 template payload into a fixed TEMPLATE_SIZE buffer.
     * Returns null when the payload is not valid base64 or decodes to empty bytes.
     */
    private static byte[] decodeTemplateBase64(String base64) {
        if (base64 == null || base64.trim().isEmpty()) return null;
        try {
            byte[] decoded = Base64.getDecoder().decode(base64);
            if (decoded.length == 0) return null;
            byte[] fixed = new byte[TEMPLATE_SIZE];
            System.arraycopy(decoded, 0, fixed, 0, Math.min(decoded.length, TEMPLATE_SIZE));
            return fixed;
        } catch (IllegalArgumentException ex) {
            return null;
        }
    }

    /**
     * Very simple extraction of the "templates" array of objects.
     * Returns list of String[]{user_id, template, template_length}.
     */
    private static List<String[]> parseTemplatesArray(String json) {
        List<String[]> result = new ArrayList<>();
        int arrStart = json.indexOf("[");
        int arrEnd   = json.lastIndexOf("]");
        if (arrStart < 0 || arrEnd < 0) return result;
        String arr = json.substring(arrStart + 1, arrEnd);
        // split on },{
        int depth = 0;
        int objStart = -1;
        for (int i = 0; i < arr.length(); i++) {
            char c = arr.charAt(i);
            if (c == '{') { if (depth == 0) objStart = i; depth++; }
            else if (c == '}') {
                depth--;
                if (depth == 0 && objStart >= 0) {
                    String obj = arr.substring(objStart, i + 1);
                    String uid = jsonString(obj, "user_id");
                    String tpl = jsonString(obj, "template");
                    String len = jsonString(obj, "template_length");
                    if (uid != null && tpl != null) {
                        result.add(new String[]{uid, tpl, len != null ? len : "0"});
                    }
                    objStart = -1;
                }
            }
        }
        return result;
    }

    // =========================================================================
    // Handlers
    // =========================================================================

    // ── /status ──────────────────────────────────────────────────────────────
    class StatusHandler implements HttpHandler {
        @Override public void handle(HttpExchange ex) throws IOException {
            if (!handlePreflight(ex)) return;
            boolean alive = ensureDevice();
            int count = sdkInitialized ? ZKFPService.GetDeviceCount() : 0;
            sendJson(ex, 200, String.format(
                "{\"ok\":true,\"sdk_initialized\":%s,\"device_connected\":%s,\"device_count\":%d,\"image_width\":%d,\"image_height\":%d}",
                sdkInitialized, alive, count, fpWidth, fpHeight
            ));
        }
    }

    // ── /enroll/start ─────────────────────────────────────────────────────────
    class EnrollStartHandler implements HttpHandler {
        @Override public void handle(HttpExchange ex) throws IOException {
            if (!handlePreflight(ex)) return;
            synchronized (FingerprintBridgeServer.this) {
                enrollStep    = 1;
                enrollBuffers = new byte[ENROLL_STEPS][TEMPLATE_SIZE];
                enrollLengths = new int[ENROLL_STEPS];
            }
            if (!ensureDevice()) {
                sendJson(ex, 503, "{\"ok\":false,\"error\":\"Fingerprint device not connected\"}");
                return;
            }
            sendJson(ex, 200,
                "{\"ok\":true,\"step\":1,\"total_steps\":3,\"message\":\"Place your finger on the scanner\"}");
        }
    }

    // ── /enroll/capture ───────────────────────────────────────────────────────
    class EnrollCaptureHandler implements HttpHandler {
        @Override public void handle(HttpExchange ex) throws IOException {
            if (!handlePreflight(ex)) return;

            int stepNow;
            synchronized (FingerprintBridgeServer.this) {
                stepNow = enrollStep;
            }

            if (stepNow < 1 || stepNow > ENROLL_STEPS) {
                sendJson(ex, 400,
                    "{\"ok\":false,\"error\":\"No enrollment session active. Call /enroll/start first.\"}");
                return;
            }

            if (!ensureDevice()) {
                sendJson(ex, 503, "{\"ok\":false,\"error\":\"Fingerprint device not connected\"}");
                return;
            }

            int[]  tplLen = new int[]{TEMPLATE_SIZE};
            byte[] tpl    = acquireWithTimeout(tplLen);

            if (tpl == null) {
                sendJson(ex, 408,
                    "{\"ok\":false,\"message\":\"Capture timed out. Please place finger firmly on scanner.\"}");
                return;
            }

            synchronized (FingerprintBridgeServer.this) {
                // Copy the full 2048-byte buffer and remember the valid length
                System.arraycopy(tpl, 0, enrollBuffers[stepNow - 1], 0, TEMPLATE_SIZE);
                enrollLengths[stepNow - 1] = tplLen[0];

                if (stepNow < ENROLL_STEPS) {
                    enrollStep = stepNow + 1;
                    sendJson(ex, 200, String.format(
                        "{\"ok\":true,\"step\":%d,\"total_steps\":3,\"done\":false," +
                        "\"message\":\"Scan %d of 3 captured. Lift finger and place again.\"}",
                        stepNow + 1, stepNow));
                    return;
                }

                // All 3 captures done → merge into one registration template.
                // GenRegFPTemplate expects full 2048-byte buffers for each scan.
                byte[] regTemplate = new byte[TEMPLATE_SIZE];
                int[]  regLen      = new int[]{TEMPLATE_SIZE};
                int mergeRet = ZKFPService.GenRegFPTemplate(
                    enrollBuffers[0], enrollBuffers[1], enrollBuffers[2],
                    regTemplate, regLen);

                enrollStep = 0;

                if (mergeRet != 0) {
                    sendJson(ex, 500, String.format(
                        "{\"ok\":false,\"error\":\"Template merge failed (code %d). " +
                        "Ensure the same finger was used for all 3 scans and try again.\"}",
                        mergeRet));
                    return;
                }

                // BlobToBase64 converts the first regLen[0] valid bytes to base64.
                String b64 = ZKFPService.BlobToBase64(regTemplate, regLen[0]);
                sendJson(ex, 200, String.format(
                    "{\"ok\":true,\"step\":3,\"total_steps\":3,\"done\":true," +
                    "\"template\":\"%s\",\"template_length\":%d," +
                    "\"message\":\"Enrollment complete!\"}",
                    b64, regLen[0]));
            }
        }
    }

    // ── /verify ───────────────────────────────────────────────────────────────
    class VerifyHandler implements HttpHandler {
        @Override public void handle(HttpExchange ex) throws IOException {
            if (!handlePreflight(ex)) return;

            String body = readBody(ex);
            String templateBase64 = jsonString(body, "template");
            Integer templateLength = jsonInt(body, "template_length");

            if (templateBase64 == null || templateBase64.isEmpty()) {
                sendJson(ex, 400, "{\"ok\":false,\"error\":\"Missing 'template' field\"}");
                return;
            }
            if (templateLength == null || templateLength <= 0) {
                sendJson(ex, 400, "{\"ok\":false,\"error\":\"Missing or invalid 'template_length'\"}");
                return;
            }

            if (!ensureDevice()) {
                sendJson(ex, 503, "{\"ok\":false,\"error\":\"Fingerprint device not connected\"}");
                return;
            }

            // Decode stored template with standard base64 decoder.
            // This is more tolerant with templates encoded by non-SDK code paths.
            byte[] storedTpl = decodeTemplateBase64(templateBase64);
            if (storedTpl == null) {
                sendJson(ex, 400,
                    "{\"ok\":false,\"error\":\"Template payload is not valid base64\"}");
                return;
            }

            // Capture live finger
            int[]  liveLen = new int[]{TEMPLATE_SIZE};
            byte[] liveTpl = acquireWithTimeout(liveLen);
            if (liveTpl == null) {
                sendJson(ex, 408, "{\"ok\":false,\"message\":\"Capture timed out. Place finger on scanner.\"}");
                return;
            }

            // 1:1 match
            synchronized (FingerprintBridgeServer.this) {
                int score = ZKFPService.MatchFP(storedTpl, liveTpl);
                boolean matched = score > 0;
                sendJson(ex, 200, String.format(
                    "{\"ok\":true,\"matched\":%s,\"score\":%d}", matched, Math.max(0, score)));
            }
        }
    }

    // ── /identify ─────────────────────────────────────────────────────────────
    class IdentifyHandler implements HttpHandler {
        @Override public void handle(HttpExchange ex) throws IOException {
            if (!handlePreflight(ex)) return;

            String body = readBody(ex);
            List<String[]> entries = parseTemplatesArray(body);

            if (entries.isEmpty()) {
                sendJson(ex, 400,
                    "{\"ok\":false,\"error\":\"'templates' array is empty or missing\"}");
                return;
            }

            if (!ensureDevice()) {
                sendJson(ex, 503, "{\"ok\":false,\"error\":\"Fingerprint device not connected\"}");
                return;
            }

            // ── Capture live finger BEFORE loading DB ─────────────────────
            int[]  liveLen = new int[]{TEMPLATE_SIZE};
            byte[] liveTpl = acquireWithTimeout(liveLen);
            if (liveTpl == null) {
                sendJson(ex, 408, "{\"ok\":false,\"message\":\"Capture timed out. Place finger on scanner.\"}");
                return;
            }

            synchronized (FingerprintBridgeServer.this) {
                // Clear the global DB first
                ZKFPService.DBClear();

                // Map fid (1-based) → user_id string
                Map<Integer, String> fidToUserId = new LinkedHashMap<>();
                int fid = 1;
                int decodeFailures = 0;

                for (String[] entry : entries) {
                    String userIdStr = entry[0];
                    String base64    = entry[1];
                    if (base64 == null || base64.isEmpty()) continue;

                    byte[] tmpl = decodeTemplateBase64(base64);
                    if (tmpl == null) {
                        decodeFailures++;
                        LOG.warning("Template decode failed for user_id=" + userIdStr + " – skipping.");
                        continue;
                    }

                    int addRet = ZKFPService.DBAdd(fid, tmpl);
                    if (addRet != 0) {
                        LOG.warning("DBAdd failed for user_id=" + userIdStr
                            + " fid=" + fid + " code=" + addRet + " – skipping.");
                        continue;
                    }
                    fidToUserId.put(fid, userIdStr);
                    fid++;
                }

                if (fidToUserId.isEmpty()) {
                    ZKFPService.DBClear();
                    sendJson(ex, 400, String.format(
                        "{\"ok\":false,\"error\":\"No valid templates could be loaded (decode_failures=%d,total=%d)\"}",
                        decodeFailures, entries.size()));
                    return;
                }

                // 1:N identify – liveTpl is a full 2048-byte buffer whose
                // first liveLen[0] bytes are the valid template data.
                // The SDK reads the template's internal header to find the length.
                int[] matchedFid   = new int[1];
                int[] matchedScore = new int[1];
                int identRet = ZKFPService.IdentifyFP(liveTpl, matchedFid, matchedScore);

                // Always clear DB after use
                ZKFPService.DBClear();

                LOG.info("IdentifyFP result=" + identRet
                    + " fid=" + matchedFid[0] + " score=" + matchedScore[0]);

                if (identRet != 0 || matchedFid[0] <= 0) {
                    sendJson(ex, 200,
                        "{\"ok\":true,\"matched\":false,\"user_id\":null,\"score\":0," +
                        "\"message\":\"Fingerprint not recognised\"}");
                    return;
                }

                String matchedUserId = fidToUserId.get(matchedFid[0]);
                if (matchedUserId == null) {
                    sendJson(ex, 500,
                        "{\"ok\":false,\"error\":\"Matched fid " + matchedFid[0] + " not found in map\"}");
                    return;
                }

                sendJson(ex, 200, String.format(
                    "{\"ok\":true,\"matched\":true,\"user_id\":%s,\"score\":%d}",
                    matchedUserId, matchedScore[0]));
            }
        }
    }
}
