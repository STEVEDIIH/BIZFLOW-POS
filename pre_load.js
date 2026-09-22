// preload.js
const { contextBridge, ipcRenderer } = require('electron');

// Expose safe APIs to the renderer
contextBridge.exposeInMainWorld('electron', {
    // You can add functions here if needed
    // For now, just an empty object to satisfy the preload requirement
});

// Log that preload is working
console.log('BizFlow: Preload script loaded successfully');