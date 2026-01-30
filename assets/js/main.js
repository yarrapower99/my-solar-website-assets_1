// Helper function to fetch images from GitHub
function fetchGitHubImages(path, container, processFn) {
    const repoOwner = "yarrapower99";
    const repoName = "my-solar-website-assets_1";
    const apiUrl = `https://api.github.com/repos/${repoOwner}/${repoName}/contents/${path}?per_page=100`;

    fetch(apiUrl)
        .then(res => res.json())
        .then(data => {
            if (!Array.isArray(data)) return;
            const images = data.filter(item => item.name.match(/\.(jpg|jpeg|png|gif|webp|svg)$/i));
            processFn(images, container);
        })
        .catch(err => console.error(`Error loading images from ${path}:`, err));
}

document.addEventListener("DOMContentLoaded", () => {
    // Hero Slider
    const sliderContainer = document.querySelector('.hero-slider');
    if (sliderContainer) {
        const heroContent = document.querySelector('.hero-content');
        fetchGitHubImages("assets/profile", sliderContainer, (images, container) => {
            images.forEach((file, index) => {
                const slide = document.createElement('div');
                slide.classList.add('slide');
                if (index === 0) slide.classList.add('active');
                slide.style.backgroundImage = `url('${file.download_url}')`;
                container.insertBefore(slide, heroContent);
            });

            let slides = document.querySelectorAll(".hero-slider .slide");
            if (slides.length > 1) {
                let currentIndex = 0;
                setInterval(() => {
                    slides[currentIndex].classList.remove("active");
                    currentIndex = (currentIndex + 1) % slides.length;
                    slides[currentIndex].classList.add("active");
                }, 4000);
            }
        });
    }

    // Partners Logos
    const logosContainer = document.getElementById('partners-logos');
    if (logosContainer) {
        fetchGitHubImages("assets/logo_use", logosContainer, (images, container) => {
            const fragment = document.createDocumentFragment();
            [...images, ...images].forEach(file => {
                const img = document.createElement("img");
                img.src = file.download_url;
                img.alt = file.name.split('.')[0];
                fragment.appendChild(img);
            });
            container.appendChild(fragment);

            setInterval(() => {
                const firstItem = container.firstElementChild;
                if (!firstItem) return;
                const itemWidth = firstItem.offsetWidth;
                const gap = 60;
                container.style.transition = "transform 0.5s ease-in-out";
                container.style.transform = `translateX(-${itemWidth + gap}px)`;
                container.addEventListener('transitionend', () => {
                    container.style.transition = "none";
                    container.style.transform = "translateX(0)";
                    container.appendChild(firstItem);
                }, { once: true });
            }, 3000);
        });
    }

    // Stats Section Counter Animation
    const statsSection = document.querySelector(".stats-section");
    if (statsSection) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounters();
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });
        observer.observe(statsSection);
    }

    // Initial language update
    if (typeof updateLanguage === "function") {
        updateLanguage();
    }
});

// Portfolio Gallery_sup
const gallery = document.getElementById('portfolio-sup-gallery');

if (gallery) {
    const repoOwner = "yarrapower99";
    const repoName = "my-solar-website-assets_1";
    const path = "assets/portfolio_sup";

    const apiUrl = `https://api.github.com/repos/${repoOwner}/${repoName}/contents/${path}?per_page=100`;

    fetch(apiUrl)
        .then(res => res.json())
        .then(data => {
            if (!Array.isArray(data)) return;

            data
                .filter(item => item.name.match(/\.(jpg|jpeg|png|gif|webp)$/i))
                .forEach(file => {
                    const img = document.createElement('img');
                    img.src = file.download_url;
                    img.alt = file.name;
                    img.loading = "lazy";
                    gallery.appendChild(img);
                });
        })
        .catch(err => console.error('Portfolio_sup load error:', err));
}

// Partners Gallery
document.addEventListener('DOMContentLoaded', () => {
    const partnersGallery = document.getElementById('partners-gallery');
    if (!partnersGallery) return;

    const repoOwner = "yarrapower99";
    const repoName = "my-solar-website-assets_1";
    const path = "assets/Partners";

    const apiUrl = `https://api.github.com/repos/${repoOwner}/${repoName}/contents/${path}?per_page=100`;

    fetch(apiUrl)
        .then(res => res.json())
        .then(data => {
            if (!Array.isArray(data)) return;

            const fragment = document.createDocumentFragment();

            data
                .filter(item => item.name.match(/\.(png|jpg|jpeg|webp|svg)$/i))
                .forEach(file => {
                    const img = document.createElement('img');
                    img.src = file.download_url;
                    img.alt = file.name.replace(/\.[^/.]+$/, '');
                    img.loading = "lazy";

                    fragment.appendChild(img);
                });

            partnersGallery.appendChild(fragment);
        })
        .catch(err => console.error('Partners load error:', err));
});


// Navbar Scroll Effect
let lastScrollTop = 0;
let isMenuOpen = false; // Flag to track menu state

window.addEventListener("scroll", function () {
    const navbar = document.querySelector(".navbar");
    let scrollTop = window.scrollY;

    // Smart Navbar Logic - Don't hide if menu is open
    if (isMenuOpen) {
        navbar.classList.remove("hidden");
        return;
    }

    if (scrollTop > lastScrollTop && scrollTop > 100) {
        navbar.classList.add("hidden"); // เลื่อนลง -> ซ่อน
    } else {
        navbar.classList.remove("hidden"); // เลื่อนขึ้น -> แสดง
    }
    lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;

    if (scrollTop > 50) {
        navbar.classList.add("scrolled");
    } else {
        navbar.classList.remove("scrolled");
    }

    const backToTopBtn = document.getElementById("backToTop");
    if (scrollTop > 300) {
        backToTopBtn.classList.add("show");
    } else {
        backToTopBtn.classList.remove("show");
    }
});

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Gallery Scroll Reveal
const observerOptions = {
    threshold: 0.2
};
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('reveal');
            observer.unobserve(entry.target);
        }
    });
}, observerOptions);

// Generate Portfolio Images with Load More
const portfolioContainer = document.getElementById('portfolio-gallery');

if (portfolioContainer) {
    // ใช้ GitHub API เพื่อดึงรายชื่อไฟล์ทั้งหมดในโฟลเดอร์ portfolio
    // วิธีนี้จะทำให้เมื่อเพิ่มรูปใหม่ใน GitHub รูปจะแสดงบนเว็บอัตโนมัติโดยไม่ต้องแก้โค้ด
    const repoOwner = "yarrapower99";
    const repoName = "my-solar-website-assets_1";

    // กำหนดโฟลเดอร์และหมวดหมู่ (ต้องตรงกับชื่อโฟลเดอร์ใน GitHub)
    const categories = [
        { path: "assets/portfolio/บ้านพักอาศัย", id: "home" },
        { path: "assets/portfolio/โรงงาน และอาคารธุรกิจ", id: "factory" }
    ];

    // สร้าง Promise เพื่อดึงข้อมูลจากทุกโฟลเดอร์พร้อมกัน
    const fetchPromises = categories.map(cat => {
        // ใช้ encodeURI เพื่อรองรับภาษาไทยและเว้นวรรคใน URL
        const apiUrl = `https://api.github.com/repos/${repoOwner}/${repoName}/contents/${cat.path}`;
        return fetch(encodeURI(apiUrl))
            .then(response => {
                if (!response.ok) return []; // ถ้าหาโฟลเดอร์ไม่เจอ ให้ข้ามไป
                return response.json();
            })
            .then(data => {
                if (!Array.isArray(data)) return [];
                // กรองเฉพาะไฟล์รูปภาพ และเพิ่ม property category
                return data
                    .filter(item => item.name.match(/\.(jpg|jpeg|png|gif)$/i))
                    .map(item => ({ ...item, category: cat.id }));
            });
    });

    Promise.all(fetchPromises)
        .then(results => {
            // รวมรูปภาพจากทุกโฟลเดอร์เข้าด้วยกัน
            const allImages = results.flat();

            // เรียงลำดับตามตัวเลขในชื่อไฟล์ (ถ้ามี)
            allImages.sort((a, b) => {
                const numA = parseInt(a.name.match(/\d+/)) || 0;
                const numB = parseInt(b.name.match(/\d+/)) || 0;
                return numA - numB;
            });

            let displayedCount = 0;
            const itemsPerLoad = 6;
            const initialLoad = 12;

            function renderImages(count) {
                const fragment = document.createDocumentFragment();
                const imagesToRender = allImages.slice(displayedCount, displayedCount + count);

                imagesToRender.forEach(file => {
                    const img = document.createElement('img');
                    img.src = file.download_url;
                    img.alt = file.name;
                    img.loading = "lazy";
                    img.dataset.category = file.category;
                    img.className = 'gallery-item';

                    observer.observe(img);
                    fragment.appendChild(img);
                });

                portfolioContainer.appendChild(fragment);
                displayedCount += count;

                // อัปเดตสถานะปุ่ม Load More
                updateLoadMoreButton();
            }

            function updateLoadMoreButton() {
                let loadMoreBtn = document.getElementById('load-more-btn');

                if (!loadMoreBtn && displayedCount < allImages.length) {
                    loadMoreBtn = document.createElement('button');
                    loadMoreBtn.id = 'load-more-btn';
                    loadMoreBtn.textContent = 'โหลดรูปเพิ่มเติม';
                    loadMoreBtn.className = 'load-more-btn';
                    loadMoreBtn.addEventListener('click', () => {
                        renderImages(itemsPerLoad);
                    });
                    portfolioContainer.parentNode.insertBefore(loadMoreBtn, portfolioContainer.nextSibling);
                } else if (loadMoreBtn && displayedCount >= allImages.length) {
                    loadMoreBtn.style.display = 'none';
                }
            }

            // แสดง 12 รูปแรก
            renderImages(initialLoad);
        })
        .catch(error => console.error('Error loading portfolio images:', error));

    // Filter Logic
    const filterBtns = document.querySelectorAll('.filter-btn');
    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            // Active State
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const filterValue = btn.getAttribute('data-filter');
            const images = portfolioContainer.querySelectorAll('img');

            images.forEach(img => {
                if (filterValue === 'all' || img.dataset.category === filterValue) {
                    img.style.display = ''; // แสดงผล
                } else {
                    img.style.display = 'none'; // ซ่อน
                }
            });
        });
    });

    // Lightbox Logic
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');
    let currentLightboxIndex = 0;

    // Open Lightbox
    portfolioContainer.addEventListener('click', function (e) {
        if (e.target.tagName === 'IMG') {
            const allVisibleImages = Array.from(document.querySelectorAll('#portfolio-gallery img:not([style*="display: none"])'));
            currentLightboxIndex = allVisibleImages.indexOf(e.target);
            openLightbox();
            showLightboxSlide(currentLightboxIndex);
        }
    });

    function openLightbox() {
        lightbox.style.display = "block";
        document.body.style.overflow = "hidden"; // Disable scroll
    }

    window.closeLightbox = function () {
        lightbox.style.display = "none";
        document.body.style.overflow = "auto"; // Enable scroll
        isMenuOpen = false; // Update menu state
    }

    window.changeSlide = function (n) {
        const allVisibleImages = Array.from(document.querySelectorAll('#portfolio-gallery img:not([style*="display: none"])'));
        currentLightboxIndex += n;
        if (currentLightboxIndex >= allVisibleImages.length) currentLightboxIndex = 0;
        if (currentLightboxIndex < 0) currentLightboxIndex = allVisibleImages.length - 1;

        // Fade effect
        lightboxImg.style.opacity = 0;
        setTimeout(() => {
            lightboxImg.src = allVisibleImages[currentLightboxIndex].src;
            lightboxImg.style.opacity = 1;
        }, 200);
    }

    function showLightboxSlide(index) {
        const allVisibleImages = Array.from(document.querySelectorAll('#portfolio-gallery img:not([style*="display: none"])'));
        lightboxImg.src = allVisibleImages[index].src;
        lightboxImg.style.opacity = 1;
    }

    // Close on outside click
    lightbox.addEventListener('click', (e) => {
        if (e.target === lightbox) closeLightbox();
    });
}

// Generate Product Images
const productContainer = document.getElementById('product-gallery');
if (productContainer) {
    fetchGitHubImages("assets/products", productContainer, (images, container) => {
        const fragment = document.createDocumentFragment();
        images.forEach(file => {
            const img = document.createElement('img');
            img.src = file.download_url;
            img.alt = file.name;
            img.loading = "lazy";
            observer.observe(img);
            fragment.appendChild(img);
        });
        container.appendChild(fragment);
    });
}

// Hamburger Menu Toggle
function toggleMenu() {
    const menu = document.querySelector('.navbar .menu');
    const hamburger = document.querySelector('.hamburger');
    menu.classList.toggle('active');
    hamburger.classList.toggle('active');
    isMenuOpen = menu.classList.contains('active'); // Update menu state
}

// Close menu when clicking a link
document.querySelectorAll('.navbar .menu a').forEach(link => {
    link.addEventListener('click', () => {
        const menu = document.querySelector('.navbar .menu');
        if (menu.classList.contains('active')) {
            document.querySelector('.navbar .menu').classList.remove('active');
            document.querySelector('.hamburger').classList.remove('active');
            isMenuOpen = false; // Update menu state
        }
    });
});

// Counter Animation
function animateCounters() {
    const counters = document.querySelectorAll(".stat-number");
    counters.forEach(counter => {
        const text = counter.textContent;
        const numMatch = text.match(/(\d+)/);
        if (!numMatch) return;
        const target = parseInt(numMatch[1]);
        const prefix = text.substring(0, text.indexOf(numMatch[0]));
        const suffix = text.substring(text.indexOf(numMatch[0]) + numMatch[0].length);
        const duration = 2000;
        const step = target / (duration / 16);
        let current = 0;
        const timer = setInterval(() => {
            current += step;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            counter.textContent = prefix + Math.floor(current) + suffix;
        }, 16);
    });
}

// Language Switcher
let currentLang = localStorage.getItem('lang') || 'th';

function updateLanguage() {
    document.querySelectorAll('[data-th], [data-en]:not(#lang-toggle)').forEach(el => {
        if (el.hasAttribute('data-' + currentLang)) {
            el.textContent = el.getAttribute('data-' + currentLang);
        }
    });
    document.querySelectorAll('[data-placeholder-en]').forEach(el => {
        if (currentLang === 'en' && el.hasAttribute('data-placeholder-en')) {
            el.placeholder = el.getAttribute('data-placeholder-en');
        } else {
            // Reset to original placeholder (Thai)
            const original = el.getAttribute('placeholder');
            if (original) el.placeholder = original;
        }
    });
    document.getElementById('lang-toggle').textContent = currentLang === 'th' ? '🇹🇭 TH' : '🇺🇸 EN';
    localStorage.setItem('lang', currentLang);
}

document.getElementById('lang-toggle').addEventListener('click', () => {
    currentLang = currentLang === 'th' ? 'en' : 'th';
    updateLanguage();
});
