
        // Hero Slider - Fetch from GitHub API
        document.addEventListener("DOMContentLoaded", () => {
            const sliderContainer = document.querySelector('.hero-slider');
            const heroContent = document.querySelector('.hero-content');
            const repoOwner = "yarrapower99";
            const repoName = "my-solar-website-assets_1";
            const path = "assets/Partners"; // Path to images
            const apiUrl = `https://api.github.com/repos/${repoOwner}/${repoName}/contents/${path}`;

            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    if (!Array.isArray(data)) return;
                    const images = data.filter(item => item.name.match(/\.(jpg|jpeg|png|gif)$/i));

                    images.forEach((file, index) => {
                        const slide = document.createElement('div');
                        slide.classList.add('slide');
                        if (index === 0) slide.classList.add('active');
                        slide.style.backgroundImage = `url('${file.download_url}')`;
                        // Insert slides before the content so they are in the background
                        sliderContainer.insertBefore(slide, heroContent);
                    });

                    // Start Slider Animation
                    let slides = document.querySelectorAll(".hero-slider .slide");
                    let currentIndex = 0;

                    if (slides.length > 1) {
                        setInterval(() => {
                            slides[currentIndex].classList.remove("active");
                            currentIndex = (currentIndex + 1) % slides.length;
                            slides[currentIndex].classList.add("active");
                        }, 4000);
                    }
                })
                .catch(err => console.error("Error loading hero slider images:", err));
        });


        document.addEventListener("DOMContentLoaded", function () {
            const logosContainer = document.getElementById('partners-logos');
            if (logosContainer) {
                const repoOwner = "yarrapower99";
                const repoName = "my-solar-website-assets_1";
                const path = "assets/logo_use";
                const apiUrl = `https://api.github.com/repos/${repoOwner}/${repoName}/contents/${path}`;

                fetch(apiUrl)
                    .then(response => response.json())
                    .then(data => {
                        if (!Array.isArray(data)) return;
                        const images = data.filter(item => item.name.match(/\.(jpg|jpeg|png|gif)$/i));

                        const fragment = document.createDocumentFragment();

                        // Duplicate logos for seamless scrolling
                        [...images, ...images].forEach(file => {
                            const img = document.createElement("img");
                            img.src = file.download_url;
                            img.alt = file.name.split('.')[0];
                            fragment.appendChild(img);
                        });

                        logosContainer.appendChild(fragment);

                        // Start the animation after logos are loaded
                        setInterval(() => {
                            const firstItem = logosContainer.firstElementChild;
                            if (!firstItem) return;

                            const itemWidth = firstItem.offsetWidth;
                            const gap = 60; // CSS gap

                            logosContainer.style.transition = "transform 0.5s ease-in-out";
                            logosContainer.style.transform = `translateX(-${itemWidth + gap}px)`;

                            logosContainer.addEventListener('transitionend', () => {
                                logosContainer.style.transition = "none";
                                logosContainer.style.transform = "translateX(0)";
                                logosContainer.appendChild(firstItem);
                            }, { once: true });
                        }, 3000);
                    })
                    .catch(err => console.error("Error loading partner logos:", err));
            }
        });

        // Navbar Scroll Effect
        let lastScrollTop = 0;

        window.addEventListener("scroll", function () {
            const navbar = document.querySelector(".navbar");
            const backToTopBtn = document.getElementById("backToTop");
            let scrollTop = window.scrollY;

            // Smart Navbar Logic
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
                    const images = results.flat();

                    // เรียงลำดับตามตัวเลขในชื่อไฟล์ (ถ้ามี)
                    images.sort((a, b) => {
                        const numA = parseInt(a.name.match(/\d+/)) || 0;
                        const numB = parseInt(b.name.match(/\d+/)) || 0;
                        return numA - numB;
                    });

                    const fragment = document.createDocumentFragment();

                    images.forEach(file => {
                        const img = document.createElement('img');
                        img.src = file.download_url; // ใช้ URL ตรงจาก API ไม่ต้องเดานามสกุล
                        img.alt = file.name;
                        img.loading = "lazy";
                        img.dataset.category = file.category; // กำหนดหมวดหมู่จากโฟลเดอร์ต้นทาง

                        observer.observe(img);
                        fragment.appendChild(img);
                    });

                    portfolioContainer.appendChild(fragment);
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
                    const allImages = Array.from(document.querySelectorAll('#portfolio-gallery img'));
                    currentLightboxIndex = allImages.indexOf(e.target);
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
            }

            window.changeSlide = function (n) {
                const allImages = document.querySelectorAll('#portfolio-gallery img');
                currentLightboxIndex += n;
                if (currentLightboxIndex >= allImages.length) currentLightboxIndex = 0;
                if (currentLightboxIndex < 0) currentLightboxIndex = allImages.length - 1;

                // Fade effect
                lightboxImg.style.opacity = 0;
                setTimeout(() => {
                    lightboxImg.src = allImages[currentLightboxIndex].src;
                    lightboxImg.style.opacity = 1;
                }, 200);
            }

            function showLightboxSlide(index) {
                const allImages = document.querySelectorAll('#portfolio-gallery img');
                lightboxImg.src = allImages[index].src;
                lightboxImg.style.opacity = 1;
            }

            // Close on outside click
            lightbox.addEventListener('click', (e) => {
                if (e.target === lightbox) closeLightbox();
            });
        }

        // Hamburger Menu Toggle
        function toggleMenu() {
            const menu = document.querySelector('.navbar .menu');
            const hamburger = document.querySelector('.hamburger');
            menu.classList.toggle('active');
            hamburger.classList.toggle('active');
        }

        // Close menu when clicking a link
        document.querySelectorAll('.navbar .menu a').forEach(link => {
            link.addEventListener('click', () => {
                document.querySelector('.navbar .menu').classList.remove('active');
                document.querySelector('.hamburger').classList.remove('active');
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

document.addEventListener("DOMContentLoaded", () => {
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
});
