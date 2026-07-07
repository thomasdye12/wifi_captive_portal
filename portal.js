(function () {
  const params = new URLSearchParams(window.location.search);
  const status = document.getElementById("status");
  const oauthButtons = document.getElementById("oauth-buttons");
  const detailSsid = document.getElementById("detail-ssid");
  const detailDevice = document.getElementById("detail-device");
  const detailTime = document.getElementById("detail-time");

  function siteFromPath() {
    const match = window.location.pathname.match(/\/guest\/s\/([^/]+)/);
    return match ? decodeURIComponent(match[1]) : "";
  }

  function clientPayload(extra) {
    return Object.assign({
      client_mac: params.get("id") || params.get("client_mac") || params.get("mac") || "",
      ap_mac: params.get("ap") || params.get("ap_mac") || "",
      ssid: params.get("ssid") || "",
      site: params.get("site") || siteFromPath(),
      redirect_url: params.get("url") || params.get("redirect_url") || ""
    }, extra || {});
  }

  function setStatus(message, kind) {
    status.textContent = message || "";
    status.className = "status" + (kind ? " " + kind : "");
  }

  function setDetails() {
    const client = clientPayload();
    detailSsid.textContent = client.ssid || "Guest WiFi";
    detailDevice.textContent = client.client_mac || "Waiting for UniFi details";
    detailTime.textContent = new Date().toUTCString();
  }

  async function redeem(kind, code) {
    const endpoint = kind === "guest" ? "/API/portal/guest-code" : "/API/portal/voucher";
    setStatus("Checking code...");
    const response = await fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify(clientPayload({ code }))
    });
    const data = await response.json();

    if (!response.ok || !data.ok) {
      throw new Error(data.error || "Access was not approved.");
    }

    setStatus("Access approved. Connecting...", "ok");
    window.location.assign(data.redirect_url || "/");
  }

  document.querySelectorAll(".tab").forEach((tab) => {
    tab.addEventListener("click", () => {
      document.querySelectorAll(".tab").forEach((item) => {
        item.classList.toggle("active", item === tab);
        item.setAttribute("aria-selected", item === tab ? "true" : "false");
      });
      document.querySelectorAll(".panel").forEach((panel) => {
        panel.classList.toggle("active", panel.id === tab.dataset.panel + "-panel");
      });
      setStatus("");
    });
  });

  document.querySelectorAll("form[data-kind]").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = form.querySelector("button");
      const code = new FormData(form).get("code");
      button.disabled = true;
      try {
        await redeem(form.dataset.kind, String(code || "").trim());
      } catch (error) {
        setStatus(error.message, "error");
      } finally {
        button.disabled = false;
      }
    });
  });

  fetch("/API/portal/config", { credentials: "include" })
    .then((response) => response.json())
    .then((config) => {
      (config.oauth_providers || []).forEach((provider) => {
        const link = document.createElement("a");
        const query = new URLSearchParams(clientPayload()).toString();
        link.href = "/API/portal/oauth/" + encodeURIComponent(provider.id) + "/start?" + query;
        link.textContent = provider.label || "Login";
        oauthButtons.appendChild(link);
      });
    })
    .catch(() => {
      setStatus("Portal configuration could not be loaded.", "error");
    });

  setDetails();
})();
