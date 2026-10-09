const puppeteer = require('puppeteer');

(async () => {
  const browser = await puppeteer.launch();
  const page = await browser.newPage();
  await page.goto('file:///Users/adonispallassutanto/Documents/vms/hyperframes_videos/index.html');
  
  // Wait for GSAP to load and timeline to initialize
  await page.waitForFunction(() => window.__timelines && window.__timelines["main"]);
  
  const result = await page.evaluate(() => {
    const tl = window.__timelines["main"];
    tl.time(10); // Seek to 10 seconds
    const feat4 = document.querySelector("#feat-4 .inner-feature");
    const intro = document.querySelector("#intro .inner");
    return {
      feat4Opacity: feat4 ? feat4.style.opacity : null,
      feat4Transform: feat4 ? feat4.style.transform : null,
      introOpacity: intro ? intro.style.opacity : null,
      timelineDuration: tl.duration(),
      gsapVersion: window.gsap.version
    };
  });
  
  console.log("GSAP State at t=10s:");
  console.log(result);
  
  await browser.close();
})();
