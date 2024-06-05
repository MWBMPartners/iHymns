document.getElementById('searchButton').addEventListener('click', function() {
    const searchTerm = document.getElementById('searchInput').value;
    fetch(`https://www.iHymns.net/api/api_get_song.php?search=${searchTerm}&language=en`)
      .then(response => response.json())
      .then(data => {
        const songsContainer = document.getElementById('songsContainer');
        songsContainer.innerHTML = '';
        data.forEach(song => {
          const songDiv = document.createElement('div');
          songDiv.className = 'song';
          songDiv.innerHTML = `
            <h2>${song.title}</h2>
            <p>${song.lyrics}</p>
          `;
          songsContainer.appendChild(songDiv);
        });
      });
  });
  