# Cantaloupe delegate script for Omeka S module Image Server.
#
# Case A: resolve a clean, extension-less IIIF identifier (the Omeka media
# "storage id", option "Filename" of "Media identifier") to an original file,
# so an external Cantaloupe sharing the Omeka "files/original" directory can be
# used without exposing the file extension in the IIIF url. The identifier with
# extension (option "Filename with extension") is supported too.
#
# Resolution is filesystem only (no database, no directory glob): the storage
# id is a flat hash and the originals directory may hold hundreds of thousands
# of files, so each candidate extension is probed with a direct stat, which is
# O(1) on a htree/B-tree directory index. Never use Dir.glob here.
#
# Images only: audio and video are served by Image Server / Iiif Server, not by
# Cantaloupe, so they never reach this delegate.
#
# Install:
#   1. Copy to /etc/cantaloupe/delegates.rb (or mount it in the container).
#   2. In cantaloupe.properties:
#        delegate_script.enabled            = true
#        delegate_script.pathname           = /etc/cantaloupe/delegates.rb
#        source.static                      = FilesystemSource
#        FilesystemSource.lookup_strategy   = ScriptLookupStrategy
#   3. Set ORIGINAL_DIR below to the originals directory as seen from inside
#      the Cantaloupe container, and OMEKA_HOST / OMEKA_PORT when the module
#      Access is used (see below).
#   4. Restart Cantaloupe.
#
# Module Access: set OMEKA_ACCESS=1 to ask Omeka, through the endpoint
# "/access/authorize", whether the client may see the media. Add the Cantaloupe
# container ip to the Access setting "access_ip_proxy_trusted", so the client ip
# sent in X-Forwarded-For is used. The request is denied when Omeka does not
# answer in time (fail-closed). Without OMEKA_ACCESS, every request is allowed.

require 'net/http'
require 'uri'

class CustomDelegate

  attr_accessor :context

  # Originals directory, as mounted inside the Cantaloupe container.
  ORIGINAL_DIR = ENV['OMEKA_ORIGINAL_DIR'] || '/files/original'

  # Image extensions probed for an extension-less identifier, most frequent
  # first. Audio and video are intentionally excluded (served by Iiif Server).
  IMAGE_EXTENSIONS = %w[jpg jpeg png tif tiff jp2 gif webp].freeze

  # Omeka, reachable from inside the Cantaloupe container, and its public host
  # name when the virtual host requires it.
  OMEKA_HOST = ENV['OMEKA_HOST'] || 'omeka.example.org'
  OMEKA_PORT = (ENV['OMEKA_PORT'] || 80).to_i
  OMEKA_PUBLIC_HOST = ENV['OMEKA_PUBLIC_HOST']

  # Module Access: ask Omeka whether the client may see the media.
  ACCESS_CHECK = %w[1 true yes].include?(ENV['OMEKA_ACCESS'].to_s.downcase)
  ACCESS_TTL = 120
  ACCESS_MAX = 2000

  @@access = {}
  @@access_mutex = Mutex.new

  def source(options = {})
    'FilesystemSource'
  end

  def filesystemsource_pathname(options = {})
    identifier = context['identifier'].to_s
    return nil if identifier.empty?

    # Reject path traversal and any slash: the storage id is a flat hash, and
    # slash-bearing identifiers (e.g. arks) are handled by another resolver.
    return nil if identifier.include?('..') || identifier.include?('/')

    # 1. Identifier already carries the extension: use it directly.
    candidate = File.join(ORIGINAL_DIR, identifier)
    return candidate if File.file?(candidate)

    # 2. Extension-less storage id: probe known image extensions by stat.
    base = identifier.sub(/\.[A-Za-z0-9]+\z/, '')
    IMAGE_EXTENSIONS.each do |ext|
      candidate = File.join(ORIGINAL_DIR, "#{base}.#{ext}")
      return candidate if File.file?(candidate)
    end

    nil
  end

  def pre_authorize(options = {})
    return true unless ACCESS_CHECK

    filename = access_filename
    return false if filename.nil?

    key = "#{filename}|#{context['client_ip']}"
    now = Time.now.to_i
    @@access_mutex.synchronize do
      hit = @@access[key]
      return hit[:allowed] if hit && hit[:expires] > now
    end

    allowed = fetch_decision(filename)
    # Fail-closed, without cache, so a slow Omeka does not hide free images for
    # the whole ttl.
    return false if allowed.nil?

    @@access_mutex.synchronize do
      @@access[key] = { allowed: allowed, expires: now + ACCESS_TTL }
      if @@access.size > ACCESS_MAX
        @@access.delete_if { |_, v| v[:expires] < now }
      end
    end

    allowed
  end

  def authorize(options = {})
    pre_authorize(options)
  end

  # Ask the module Access. Returns nil when Omeka is unreachable or too slow.
  def fetch_decision(filename)
    uri = URI::HTTP.build(
      host: OMEKA_HOST,
      port: OMEKA_PORT,
      path: '/access/authorize',
      query: URI.encode_www_form(filename: filename)
    )
    req = Net::HTTP::Get.new(uri)
    req['Host'] = OMEKA_PUBLIC_HOST if OMEKA_PUBLIC_HOST
    req['X-Forwarded-For'] = context['client_ip'] if context['client_ip']
    cookies = context['cookies']
    if cookies && !cookies.empty?
      req['Cookie'] = cookies.map { |k, v| "#{k}=#{v}" }.join('; ')
    end
    res = Net::HTTP.start(uri.host, uri.port, open_timeout: 2, read_timeout: 3, max_retries: 0) do |http|
      http.request(req)
    end
    # Only a 200 allows: Access answers 404 for a reserved media too.
    res.code.to_i == 200
  rescue StandardError
    nil
  end

  # The identifier is the storage id, with or without extension.
  def access_filename
    identifier = context['identifier'].to_s
    return nil if identifier.empty? || identifier.include?('..') || identifier.include?('/')

    identifier
  end

  # Stubs required by the Cantaloupe delegate interface. Return defaults so
  # every IIIF endpoint works without further customization.
  def deserialize_meta_identifier(meta_identifier); {}; end
  def serialize_meta_identifier(components); ''; end
  def extra_iiif2_information_response_keys(options = {}); {}; end
  def extra_iiif3_information_response_keys(options = {}); {}; end
  def azurestoragesource_blob_key(options = {}); nil; end
  def httpsource_resource_info(options = {}); nil; end
  def jdbcsource_database_identifier(options = {}); nil; end
  def jdbcsource_last_modified(options = {}); nil; end
  def jdbcsource_media_type(options = {}); nil; end
  def overlay(options = {}); nil; end
  def redactions(options = {}); []; end
  def metadata(options = {}); nil; end

end
