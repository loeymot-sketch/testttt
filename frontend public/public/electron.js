const electron = require('electron');
const app = electron.app;
const BrowserWindow = electron.BrowserWindow;
const {ipcMain} = require('electron')


// https://www.codementor.io/@randyfindley/how-to-build-an-electron-app-using-create-react-app-and-electron-builder-ss1k0sfer
const path = require('path');
const isDev = require('electron-dev');

let mainWindow;

function createWindow() {
  if (isDev) {
    mainWindow = new BrowserWindow({
      width: 800,
      height: 800,
      frame: false,
      enableLargerThanScreen: true,
      webPreferences: {
        allowEval: false, // This is the key!
        webSecurity: false,

      }
    });
  } else {
    mainWindow = new BrowserWindow({
      width: 1080,
      height: 1920,
      frame: false,
      resizable: false,
      webPreferences: {
        webSecurity: false,
        allowEval: false // This is the key!
      }
    });
  }
  mainWindow.loadURL(isDev ? 'http://localhost:8080' : `file://${path.join(__dirname, '../build/index.html')}`);
  if (isDev) {
    // Open the DevTools.
    mainWindow.devToolsWebContents.executeJavaScript('DevToolsAPI.enterInspectElementMode()')
    mainWindow.webContents.openDevTools();
  }
  mainWindow.on('closed', () => mainWindow = null);

}
ipcMain.on('asynchronous-message', (event, arg) => {
   console.log(arg)

   // Event emitter for sending asynchronous messages
   event.sender.send('asynchronous-reply', 'async pong')
});
// Event handler for synchronous incoming messages
ipcMain.on('synchronous-message', (event, arg) => {
   console.log(arg)

   // Synchronous event emmision
   event.returnValue = 'sync pong'
});

app.on('ready', createWindow);
app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

app.on('activate', () => {
  if (mainWindow === null) {
    createWindow();
  }
});
