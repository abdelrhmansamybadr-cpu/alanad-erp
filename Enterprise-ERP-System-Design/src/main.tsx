
  import { createRoot } from "react-dom/client";
  import App from "./app/App.tsx";
  import "./styles/index.css";

  // review mode is switched on by the link (?review=1) BEFORE the router redirects to the login page and drops the query
  try {
    const rq = new URLSearchParams(window.location.search).get("review");
    if (rq === "1") localStorage.setItem("review_mode", "1");
    if (rq === "0") localStorage.removeItem("review_mode");
  } catch { /* storage blocked: the panel just stays off */ }

  createRoot(document.getElementById("root")!).render(<App />);
  