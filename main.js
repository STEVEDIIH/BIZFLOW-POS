const { app, BrowserWindow, session } = require("electron");

let win;

function createWindow() {
    win = new BrowserWindow({
        width: 1400,
        height: 850,
        minWidth: 1100,
        minHeight: 700,

        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            devTools: true,
            // Add this to ensure proper rendering
            backgroundThrottling: false
        }
    });

    // Load BizFlow
    win.loadURL("http://127.0.0.1:81/BIZFLOW/");

    // Development logging
    win.webContents.on("did-start-loading", () => {
        console.log("BizFlow: Started loading...");
    });

    win.webContents.on("did-finish-load", () => {
        console.log("BizFlow: Finished loading.");
        
        // ============================================================
        // ELECTRON FIX: Ensure DOM is fully rendered
        // ============================================================
        win.webContents.executeJavaScript(`
            // Force a re-render after page loads
            setTimeout(function() {
                document.body.style.display = 'none';
                setTimeout(function() {
                    document.body.style.display = '';
                }, 10);
            }, 500);
        `);
    });

    win.webContents.on(
        "did-fail-load",
        (event, errorCode, errorDescription) => {
            console.log(
                "BizFlow load failed:",
                errorCode,
                errorDescription
            );
        }
    );

    // Helpful while developing
    win.webContents.on("render-process-gone", (event, details) => {
        console.log("Renderer process stopped:", details);
    });

    win.on("closed", () => {
        win = null;
    });

    win.webContents.on("before-input-event", (event, input) => {
        console.log(
            "KEY:",
            input.type,
            input.key,
            "focused:",
            win.isFocused()
        );
    });

    // ============================================================
    // ELECTRON FIX: Force focus when window is active
    // ============================================================
    win.on("focus", () => {
        console.log("Electron window FOCUSED");
        win.webContents.executeJavaScript(`
            // When window gets focus, ensure inputs are enabled
            document.querySelectorAll('input, select, textarea').forEach(function(el) {
                el.disabled = false;
                el.readOnly = false;
                el.style.pointerEvents = 'auto';
                el.style.opacity = '1';
            });
        `);
    });

    win.on("blur", () => {
        console.log("Electron window LOST FOCUS");
    });

    // Open DevTools while developing
    win.webContents.openDevTools();
}

app.whenReady().then(() => {
    createWindow();

    app.on("activate", () => {
        if (BrowserWindow.getAllWindows().length === 0) {
            createWindow();
        }
    });
});

app.on("window-all-closed", () => {
    if (process.platform !== "darwin") {
        app.quit();
    }
});